<?php

declare(strict_types=1);

namespace OCA\Merlin\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Verschlüsselte Paywall-Abo-Zugangsdaten eines Nutzers für eine Domain (z. B.
 * Tagesspiegel Plus). username/password sind über OCP\Security\ICrypto
 * verschlüsselt, sessionCookies ist ein verschlüsseltes JSON-Objekt
 * (Cookie-Name => Wert, siehe <persist-cookie> in der content-filter-XML der
 * Domain) - siehe SiteCredentialService, das ver-/entschlüsselt, bevor es
 * dieser Klasse Werte übergibt bzw. von ihr liest.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getDomain()
 * @method void setDomain(string $domain)
 * @method string getUsernameEnc()
 * @method void setUsernameEnc(string $usernameEnc)
 * @method string getPasswordEnc()
 * @method void setPasswordEnc(string $passwordEnc)
 * @method string|null getSessionCookiesEnc()
 * @method void setSessionCookiesEnc(?string $sessionCookiesEnc)
 * @method \DateTime|null getCookieExpiresAt()
 * @method void setCookieExpiresAt(?\DateTime $cookieExpiresAt)
 * @method string getLastLoginStatus()
 * @method void setLastLoginStatus(string $lastLoginStatus)
 * @method \DateTime|null getLastLoginAt()
 * @method void setLastLoginAt(?\DateTime $lastLoginAt)
 * @method \DateTime getCreatedAt()
 * @method void setCreatedAt(\DateTime $createdAt)
 */
class SiteCredential extends Entity {
	/** Letzter Login-Versuch war erfolgreich, Cookie (falls vorhanden) gilt als gültig. */
	public const STATUS_OK = 'ok';
	/** Letzter Login-Versuch ist an falschen Zugangsdaten gescheitert. */
	public const STATUS_INVALID_CREDENTIALS = 'invalid_credentials';
	/** Login-Ablauf der Seite konnte nicht (mehr) durchlaufen werden (Formular/Endpoint geändert). */
	public const STATUS_LOGIN_FLOW_BROKEN = 'login_flow_broken';
	/** Noch nie versucht (frisch angelegter Eintrag). */
	public const STATUS_PENDING = 'pending';

	protected $userId;
	protected $domain;
	protected $usernameEnc;
	protected $passwordEnc;
	protected $sessionCookiesEnc;
	protected $cookieExpiresAt;
	protected $lastLoginStatus;
	protected $lastLoginAt;
	protected $createdAt;

	public function __construct() {
		$this->addType('userId', 'string');
		$this->addType('domain', 'string');
		$this->addType('usernameEnc', 'string');
		$this->addType('passwordEnc', 'string');
		$this->addType('sessionCookiesEnc', 'string');
		$this->addType('cookieExpiresAt', 'datetime');
		$this->addType('lastLoginStatus', 'string');
		$this->addType('lastLoginAt', 'datetime');
		$this->addType('createdAt', 'datetime');
	}
}
