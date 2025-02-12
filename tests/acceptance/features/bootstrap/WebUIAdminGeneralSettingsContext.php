<?php

/**
 * ownCloud
 *
 * @author Paurakh Sharma Humagain <paurakh@jankaritech.com>
 * @copyright Copyright (c) 2018 Paurakh Sharma Humagain paurakh@jankaritech.com
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License,
 * as published by the Free Software Foundation;
 * either version 3 of the License, or any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program. If not, see <http://www.gnu.org/licenses/>
 *
 */

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\TableNode;
use Behat\MinkExtension\Context\RawMinkContext;
use Page\AdminGeneralSettingsPage;
use PHPUnit\Framework\Assert;
use TestHelpers\AppConfigHelper;
use TestHelpers\SetupHelper;

require_once 'bootstrap.php';

/**
 * WebUI AdminGeneralSettings context.
 */
class WebUIAdminGeneralSettingsContext extends RawMinkContext implements Context {
	private $adminGeneralSettingsPage;

	/**
	 *
	 * @var WebUIGeneralContext
	 */
	private $webUIGeneralContext;

	/**
	 *
	 * @var FeatureContext
	 */
	private $featureContext;

	private $appParameterValues = null;
	private $logLevelValue = null;

	/**
	 * WebUIAdminAdminSettingsContext constructor.
	 *
	 * @param AdminGeneralSettingsPage $adminGeneralSettingsPage
	 */
	public function __construct(
		AdminGeneralSettingsPage $adminGeneralSettingsPage
	) {
		$this->adminGeneralSettingsPage = $adminGeneralSettingsPage;
	}

	/**
	 * @Given the administrator has browsed to the admin general settings page
	 * @When the administrator browses to the admin general settings page
	 *
	 * @return void
	 */
	public function theAdministratorHasBrowsedToTheAdminGeneralSettingsPage() {
		$this->webUIGeneralContext->adminLogsInUsingTheWebUI();
		$this->adminGeneralSettingsPage->open();
		$this->adminGeneralSettingsPage->waitTillPageIsLoaded($this->getSession());
	}

	/**
	 * @When the administrator sets the following email server settings using the webUI
	 *
	 * @param TableNode $emailSettingsTable table of email server settings headings: must be: | setting | and | value |
	 *
	 * @return void
	 */
	public function administratorSetsTheFollowingSettingsInEmailServerSettingUsingTheWebui(
		TableNode $emailSettingsTable
	) {
		$this->adminGeneralSettingsPage->setEmailServerSettings(
			$this->getSession(),
			$emailSettingsTable
		);
	}

	/**
	 * @When the administrator clicks on send test email in the admin general settings page using the webUI
	 *
	 * @return void
	 */
	public function theAdministratorClicksOnSendTestEmailInTheAdminGeneralSettingsPageUsingTheWebui() {
		$this->adminGeneralSettingsPage->sendTestEmail($this->getSession());
	}

	/**
	 * @When the administrator sets the value of imprint url to :imprintUrl using the webUI
	 *
	 * @param string $imprintUrl
	 *
	 * @return void
	 */
	public function theAdministratorSetsTheValueOfImprintUrlToUsingTheWebui($imprintUrl) {
		$this->adminGeneralSettingsPage->setLegalUrl("Imprint", $imprintUrl);
	}

	/**
	 * @When the administrator sets the value of privacy policy url to :privacyPolicyUrl using the webUI
	 *
	 * @param string $privacyPolicyUrl
	 *
	 * @return void
	 */
	public function theAdministratorSetsTheValueOfPrivacyPolicyUrlToUsingTheWebui($privacyPolicyUrl) {
		$this->adminGeneralSettingsPage->setLegalUrl("Privacy Policy", $privacyPolicyUrl);
	}

	/**
	 * @When the administrator sets the value of update channel to :updateChannel using the webUI
	 *
	 * @param string $updateChannel
	 *
	 * @return void
	 */
	public function theAdministratorSetsTheValueOfUpdateChannelUsingTheWebui($updateChannel) {
		$this->adminGeneralSettingsPage->setUpdateChannelValue($updateChannel);
	}

	/**
	 * @When the administrator sets the value of cron job to :cronJob using the webUI
	 *
	 * @param string $cronJob
	 *
	 * @return void
	 */
	public function theAdministratorSetsTheValueOfCronJobToUsingTheWebui($cronJob) {
		$this->adminGeneralSettingsPage->setCronJobValue($cronJob);
	}

	/**
	 * @When the administrator sets the value of log level to :logLevel using the webUI
	 *
	 * @param integer $logLevel
	 *
	 * @return void
	 */
	public function theAdministratorSetsTheLogLevelUsingTheWebui($logLevel) {
		$this->adminGeneralSettingsPage->setLogLevel($logLevel);
	}

	/**
	 * @When the administrator adds group :lockBreakerGroup to the lock breakers groups using the webUI
	 *
	 * @param string $lockBreakerGroup
	 *
	 * @return void
	 */
	public function theAdministratorAddsGroupToLockBreakersGroupUsingTheWebui($lockBreakerGroup) {
		$this->adminGeneralSettingsPage-> addGroupLockBreakersGroup(
			$this->getSession(),
			$lockBreakerGroup
		);
	}

	/**
	 * @Then group :expectedGroup should be listed in the lock breakers groups on the webUI
	 *
	 * @param $expectedGroup
	 *
	 * @return void
	 */
	public function groupShouldBeListedAsLockBreakersGroupInTheWebui($expectedGroup) {
		$actualGroup = $this->adminGeneralSettingsPage-> getLockBreakersGroups();
		if (!\in_array($expectedGroup, $actualGroup)) {
			Assert::assertFalse(
				"$expectedGroup should be present in lock breakers groups, but it isn't"
			);
		}
	}

	/**
	 * @Then the following groups should be listed in the lock breakers groups on the webUI
	 *
	 * @param TableNode $table
	 *
	 * @return void
	 */
	public function followingGroupsShouldBeListedInTheLockBreakersGroupsInTheWebui(TableNode $table) {
		foreach ($table as $row) {
			$this->groupShouldBeListedAsLockBreakersGroupInTheWebui($row["groups"]);
		}
	}

	/**
	 * This will run before EVERY scenario.
	 * It will set the properties for this object.
	 *
	 * @BeforeScenario @webUI
	 *
	 * @param BeforeScenarioScope $scope
	 *
	 * @return void
	 */
	public function before(BeforeScenarioScope $scope) {
		// Get the environment
		$environment = $scope->getEnvironment();
		// Get all the contexts you need in this context
		$this->featureContext = $environment->getContext('FeatureContext');
		$this->webUIGeneralContext = $environment->getContext('WebUIGeneralContext');

		// user_management app configs
		$configs = [
			'OC_Channel' => '',
			'backgroundjobs_mode' => '',
			'legal.imprint_url' => '',
			'legal.privacy_policy_url' => ''
		];

		if ($this->appParameterValues === null || $this->logLevelValue) {
			// Get app config values
			$appConfigs = AppConfigHelper::getAppConfigs(
				$this->featureContext->getBaseUrl(),
				$this->featureContext->getAdminUsername(),
				$this->featureContext->getAdminPassword(),
				'core',
				$this->featureContext->getStepLineRef()
			);
			$results = [];
			foreach ($appConfigs as $appConfig) {
				if (isset($configs[$appConfig['configkey']])) {
					$results[] = $appConfig;
				}
			}
			// Save the app configs
			$this->appParameterValues = $results;
			$this->logLevelValue = SetupHelper::getSystemConfigValue(
				"loglevel",
				$this->featureContext->getStepLineRef()
			);
		}
	}

	/**
	 * @Then the version of the owncloud installation should be displayed on the admin general settings page
	 *
	 * @return void
	 */
	public function theVersionOfOwncloudInstallationShouldBeDisplayedOnTheAdminGeneralSettingsPage() {
		$actualVersion = $this->adminGeneralSettingsPage->getOwncloudVersion();
		$expectedVersion = SetupHelper::getSystemConfigValue(
			'version',
			$this->featureContext->getStepLineRef()
		);
		Assert::assertEquals(
			\trim($expectedVersion),
			$actualVersion,
			__METHOD__
			. " The expected version to be displayed was '"
			. \trim($expectedVersion)
			. "' but got '$actualVersion' instead"
		);
	}

	/**
	 * @Then the version string of the owncloud installation should be displayed on the admin general settings page
	 *
	 * @return void
	 */
	public function theVersionStringOfTheOwncloudInstallationShouldBeDisplayedOnTheAdminGeneralSettingsPage() {
		$actualVersion = $this->adminGeneralSettingsPage->getOwncloudVersionString();
		$expectedVersion = SetupHelper::runOcc(
			['-V'],
			$this->featureContext->getStepLineRef()
		)['stdOut'];
		Assert::assertStringEndsWith(
			$actualVersion,
			\trim($expectedVersion),
			__METHOD__
			. " Expected version string is '"
			. \trim($expectedVersion)
			. "and it does not end with '$actualVersion'"
		);
	}

	/**
	 * After Scenario
	 *
	 * @AfterScenario @webUI
	 *
	 * @return void
	 */
	public function restoreScenario() {
		// Restore app config settings
		AppConfigHelper::modifyAppConfigs(
			$this->featureContext->getBaseUrl(),
			$this->featureContext->getAdminUsername(),
			$this->featureContext->getAdminPassword(),
			$this->appParameterValues,
			$this->featureContext->getStepLineRef()
		);
		SetupHelper::setSystemConfig(
			"loglevel",
			$this->logLevelValue,
			$this->featureContext->getStepLineRef()
		);
	}
}
