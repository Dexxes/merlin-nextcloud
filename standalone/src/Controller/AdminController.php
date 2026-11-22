<?php

declare(strict_types=1);

namespace Merlin\Standalone\Controller;

use Merlin\Standalone\Auth\PasswordHasher;
use Merlin\Standalone\Account\UserDataCleaner;
use Merlin\Standalone\Db\ServerSettings;
use Merlin\Standalone\Db\UserRepository;
use Merlin\Standalone\Http\Request;
use Merlin\Standalone\Http\Response;

final class AdminController {
    public function __construct(
        private readonly UserRepository $users,
        private readonly ServerSettings $settings,
        private readonly PasswordHasher $hasher,
        private readonly UserDataCleaner $userData,
    ) {
    }

    public function listUsers(Request $request): Response {
        return Response::json(array_map(UserRepository::toPublicArray(...), $this->users->findAll()));
    }

    public function createUser(Request $request): Response {
        $username = trim((string) $request->input('username', ''));
        $email = trim((string) $request->input('email', ''));
        $password = (string) $request->input('password', '');
        $role = (string) $request->input('role', 'user');

        if ($username === '' || $email === '' || strlen($password) < 8) {
            return Response::json(['error' => 'username, email and a password (min. 8 chars) are required'], 400);
        }
        if (!in_array($role, ['admin', 'user'], true)) {
            return Response::json(['error' => 'role must be admin or user'], 400);
        }
        if ($this->users->findByUsername($username) !== null) {
            return Response::json(['error' => 'username already taken'], 409);
        }
        if ($this->users->findByEmail($email) !== null) {
            return Response::json(['error' => 'email already in use'], 409);
        }

        $user = $this->users->create($username, $email, $this->hasher->hash($password), $role);
        return Response::json(UserRepository::toPublicArray($user), 201);
    }

    public function updateUser(Request $request): Response {
        $id = (int) $request->routeParam('id');
        $target = $this->users->findById($id);
        if ($target === null) {
            return Response::json(['error' => 'Not found'], 404);
        }

        $role = (string) $request->input('role', $target['role']);
        $isActiveInput = $request->input('isActive');
        $isActive = $isActiveInput === null ? (bool) $target['is_active'] : filter_var($isActiveInput, FILTER_VALIDATE_BOOLEAN);

        if (!in_array($role, ['admin', 'user'], true)) {
            return Response::json(['error' => 'role must be admin or user'], 400);
        }

        // Der letzte verbleibende Admin darf weder degradiert noch deaktiviert
        // werden - sonst gäbe es keinen Weg mehr zurück in die Verwaltung
        // (kein "physischer" Root-Zugriff auf die DB vorausgesetzt).
        $losesAdminAccess = ($target['role'] === 'admin') && ($role !== 'admin' || !$isActive);
        if ($losesAdminAccess && $this->users->countAdmins() <= 1) {
            return Response::json(['error' => 'Cannot demote or deactivate the last remaining admin'], 409);
        }

        $this->users->updateRoleAndStatus($id, $role, $isActive);
        return Response::json(UserRepository::toPublicArray($this->users->findById($id)));
    }

    public function deleteUser(Request $request): Response {
        $id = (int) $request->routeParam('id');
        $target = $this->users->findById($id);
        if ($target === null) {
            return Response::json(['error' => 'Not found'], 404);
        }
        if ($target['role'] === 'admin' && $this->users->countAdmins() <= 1) {
            return Response::json(['error' => 'Cannot delete the last remaining admin'], 409);
        }

        // Die merlin_*-Tabellen haben keine Fremdschlüssel auf users (user_id ist
        // Text wie in Nextcloud): ihre Zeilen werden hier ausdrücklich gelöscht.
        $this->userData->deleteAllFor((string) $id);
        $this->users->delete($id);
        return Response::noContent();
    }

    public function getSettings(Request $request): Response {
        return Response::json([
            'allowSelfRegistration' => $this->settings->getBool(ServerSettings::ALLOW_SELF_REGISTRATION),
        ]);
    }

    public function updateSettings(Request $request): Response {
        $value = $request->input('allowSelfRegistration');
        if ($value !== null) {
            $this->settings->set(
                ServerSettings::ALLOW_SELF_REGISTRATION,
                filter_var($value, FILTER_VALIDATE_BOOLEAN) ? '1' : '0'
            );
        }
        return $this->getSettings($request);
    }
}
