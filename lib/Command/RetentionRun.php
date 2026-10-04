<?php

declare(strict_types=1);

namespace OCA\Merlin\Command;

use OCA\Merlin\Service\RetentionService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ merlin:retention:run [--user=ID] [--dry-run]
 *
 * Führt die Löschfrist sofort aus, statt auf den täglichen
 * RetentionCleanupJob zu warten. Mit --dry-run wird nur gezählt.
 */
class RetentionRun extends Command {
	public function __construct(
		private RetentionService $retentionService,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('merlin:retention:run')
			->setDescription('Delete archived articles whose retention period has expired')
			->addOption('user', 'u', InputOption::VALUE_REQUIRED, 'Only process this user')
			->addOption('dry-run', null, InputOption::VALUE_NONE, 'Only count, do not delete');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$user = $input->getOption('user');
		$dryRun = (bool) $input->getOption('dry-run');

		$result = $this->retentionService->run(is_string($user) && $user !== '' ? $user : null, $dryRun);

		foreach ($result['users'] as $userId => $count) {
			$output->writeln(sprintf('%s: %d', $userId, $count));
		}
		$output->writeln(sprintf(
			$dryRun ? '%d archived articles would be deleted.' : '%d archived articles deleted, %d expired share links removed.',
			$result['articles'],
			$result['shares'],
		));

		return 0;
	}
}
