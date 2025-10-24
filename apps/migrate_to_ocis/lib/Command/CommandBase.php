<?php
namespace OCA\MigrateToInfiniteScale\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\Question;
use Symfony\Component\Console\Question\ChoiceQuestion;

class CommandBase extends Command {
	/**
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @param string $adminUser
	 * @return string
	 */
	protected function askAdminPassword(InputInterface $input, OutputInterface $output, string $adminUser): string {
		/** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
		$helper = $this->getHelper('question');
		$question = new Question(
			"Password for $adminUser: ",
		);
		$question->setHidden(true);
		$question->setValidator(function ($answer) {
			if (!\is_string($answer) || \strlen($answer) === 0) {
				throw new \RuntimeException('The password must not be an empty string');
			}
			return $answer;
		});
		$question->setMaxAttempts(3);

		return $helper->ask($input, $output, $question);
	}

	protected function askForDefaultRole(InputInterface $input, OutputInterface $output, array $apps) {
		/** @var \Symfony\Component\Console\Helper\QuestionHelper $helper */
		$helper = $this->getHelper('question');

		$appsDisplayName = \array_map(function ($app) {
			return $app['displayName'];
		}, $apps);

		$appIndex = 0;
		$chosenApp = $apps[$appIndex];  // it must be at least one app
		if (\count($apps) > 1) {
			$question = new ChoiceQuestion(
				'Choose the app containing the default role',
				// choices can also be PHP objects that implement __toString() method
				$appsDisplayName,
				0
			);
			$appVal = $helper->ask($input, $output, $question);
			$appIndex = \array_search($appVal, $appsDisplayName, true);
			$chosenApp = $apps[$appIndex];
		}

		$rolesDisplayName = \array_map(function ($role) {
			return $role['displayName'];
		}, $chosenApp['appRoles']);

		$roleIndex = 0;
		$chosenRole = $chosenApp['appRoles'][$roleIndex];
		$question = new ChoiceQuestion(
			'Choose the default role',
			// choices can also be PHP objects that implement __toString() method
			$rolesDisplayName,
			0
		);
		$roleVal = $helper->ask($input, $output, $question);
		$roleIndex = \array_search($roleVal, $rolesDisplayName, true);
		$chosenRole = $chosenApp['appRoles'][$roleIndex];

		return [$chosenApp['id'], $chosenRole['id']];
	}
}
