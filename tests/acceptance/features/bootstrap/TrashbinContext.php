<?php
/**
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2018, ownCloud GmbH
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;
use Behat\Gherkin\Node\TableNode;
use PHPUnit\Framework\Assert;
use Psr\Http\Message\ResponseInterface;
use TestHelpers\HttpRequestHelper;
use TestHelpers\WebDavHelper;

require_once 'bootstrap.php';

/**
 * Trashbin context
 */
class TrashbinContext implements Context {

	/**
	 *
	 * @var FeatureContext
	 */
	private $featureContext;

	/**
	 *
	 * @var OccContext
	 */
	private $occContext;

	/**
	 * @When user :user empties the trashbin using the trashbin API
	 *
	 * @param string $user user
	 *
	 * @return ResponseInterface
	 */
	public function emptyTrashbin($user) {
		$user = $this->featureContext->getActualUsername($user);
		$response = WebDavHelper::makeDavRequest(
			$this->featureContext->getBaseUrl(),
			$user,
			$this->featureContext->getPasswordForUser($user),
			'DELETE',
			"/trash-bin/$user/",
			[],
			$this->featureContext->getStepLineRef(),
			null,
			2,
			'trash-bin'
		);

		$this->featureContext->setResponse($response);
		return $response;
	}

	/**
	 * @Given user :user has emptied the trashbin
	 *
	 * @param string $user user
	 *
	 * @return void
	 */
	public function userHasEmptiedTrashbin($user) {
		$response = $this->emptyTrashbin($user);

		Assert::assertEquals(
			204,
			$response->getStatusCode(),
			__METHOD__ . " Expected status code was '204' but got '" . $response->getStatusCode() . "'"
		);
	}

	/**
	 * Get files list from the response from trashbin api
	 *
	 * @param SimpleXMLElement $responseXml
	 *
	 * @return array
	 */
	public function getTrashbinContentFromResponseXml($responseXml) {
		$xmlElements = $responseXml->xpath('//d:response');
		$files = \array_map(
			static function (SimpleXMLElement $element) {
				$href = $element->xpath('./d:href')[0];

				$propStats = $element->xpath('./d:propstat');
				$successPropStat = \array_filter(
					$propStats,
					static function (SimpleXMLElement $propStat) {
						$status = $propStat->xpath('./d:status');
						return (string) $status[0] === 'HTTP/1.1 200 OK';
					}
				);
				if (isset($successPropStat[0])) {
					$successPropStat = $successPropStat[0];

					$name = $successPropStat->xpath('./d:prop/oc:trashbin-original-filename');
					$mtime = $successPropStat->xpath('./d:prop/oc:trashbin-delete-timestamp');
					$originalLocation = $successPropStat->xpath('./d:prop/oc:trashbin-original-location');
				} else {
					$name = [];
					$mtime = [];
					$originalLocation = [];
				}

				return [
					'href' => (string) $href,
					'name' => isset($name[0]) ? (string) $name[0] : null,
					'mtime' => isset($mtime[0]) ? (string) $mtime[0] : null,
					'original-location' => isset($originalLocation[0]) ? (string) $originalLocation[0] : null
				];
			},
			$xmlElements
		);

		return $files;
	}

	/**
	 * List trashbin folder
	 *
	 * @param string $user user
	 * @param string $path path
	 * @param string $asUser - To send request as another user
	 * @param string $password
	 * @param string|int $depth
	 *
	 * @return array response
	 */
	public function listTrashbinFolder($user, $path, $asUser = null, $password = null, $depth = "infinity") {
		$asUser = $asUser ?? $user;
		$path = $path ?? '/';
		$password = $password ?? $this->featureContext->getPasswordForUser($asUser);
		$depth = (string) $depth;
		$response = WebDavHelper::listFolder(
			$this->featureContext->getBaseUrl(),
			$asUser,
			$password,
			"/trash-bin/$user/$path",
			$depth,
			$this->featureContext->getStepLineRef(),
			[
				'oc:trashbin-original-filename',
				'oc:trashbin-original-location',
				'oc:trashbin-delete-timestamp',
				'd:getlastmodified'
			],
			'trash-bin'
		);
		$this->featureContext->setResponse($response);
		$responseXml = HttpRequestHelper::getResponseXml(
			$response,
			__METHOD__
		);

		$this->featureContext->setResponseXmlObject($responseXml);
		$files = $this->getTrashbinContentFromResponseXml($responseXml);
		// filter root element
		$files = \array_filter(
			$files,
			static function ($element) use ($user, $path) {
				$path = \ltrim($path, '/');
				if ($path !== '') {
					$path .= '/';
				}
				return ($element['href'] !== "/remote.php/dav/trash-bin/$user/$path");
			}
		);
		return $files;
	}

	/**
	 * @When user :user lists the resources in the trashbin path :path with depth :depth using the WebDAV API
	 *
	 * @param $user
	 * @param $path
	 * @param $depth
	 *
	 * @return void
	 */
	public function userGetsFilesInTheTrashbinWithDepthUsingTheWebdavApi($user, $path, $depth) {
		if ($path === "/" || $path ===  "") {
			$this->listTrashbinFolder($user, $path, null, null, $depth);
			return;
		}
		$techPreviewHadToBeEnabled = $this->occContext->enableDAVTechPreview();
		$listing = $this->listTrashbinFolder($user, null);
		if ($techPreviewHadToBeEnabled) {
			$this->occContext->disableDAVTechPreview();
		}
		$originalPath = \trim($path, '/');
		if ($originalPath == "") {
			$originalPath = null;
		}

		$trashEntry = null;
		foreach ($listing as $entry) {
			if ($entry['original-location'] === $originalPath) {
				$trashEntry = $entry;
				break;
			}
		}

		Assert::assertNotNull(
			$trashEntry,
			"The first trash entry was not found while looking for trashbin entry '$path' of user '$user'"
		);
		$topPath = "/" . $this->featureContext->getBasePath() . "/remote.php/dav/trash-bin/$user";
		$topPath = WebDavHelper::sanitizeUrl($topPath);
		$path = str_replace($topPath, "", $trashEntry["href"]);

		$this->listTrashbinFolder($user, $path, null, null, $depth);
	}

	/**
	 * @Then the trashbin DAV response should not contain these nodes
	 *
	 * @param TableNode $table
	 *
	 * @return void
	 */
	public function theTrashbinDavResponseShouldNotContainTheseNodes(TableNode $table) {
		$this->featureContext->verifyTableNodeColumns($table, ['name']);
		$responseXml = $this->featureContext->getResponseXmlObject();
		$files = $this->getTrashbinContentFromResponseXml($responseXml);

		foreach ($table->getHash() as $row) {
			$path = trim($row['name'], "/");
			foreach ($files as $file) {
				if (trim($file['original-location'], "/") === $path) {
					throw new Exception("file $path was not expected in trashbin response but was found");
				}
			}
		}
	}

	/**
	 * @Then the trashbin DAV response should contain these nodes
	 *
	 * @param TableNode $table
	 *
	 * @return void
	 */
	public function theTrashbinDavResponseShouldContainTheseNodes(TableNode $table) {
		$this->featureContext->verifyTableNodeColumns($table, ['name']);
		$responseXml = $this->featureContext->getResponseXmlObject();

		$files = $this->getTrashbinContentFromResponseXml($responseXml);

		foreach ($table->getHash() as $row) {
			$path = trim($row['name'], "/");
			$found = false;
			foreach ($files as $file) {
				if (trim($file['original-location'], "/") === $path) {
					$found = true;
					break;
				}
			}
			if (!$found) {
				throw new Exception("file $path was expected in trashbin response but was not found");
			}
		}
	}

	/**
	 * Send a webdav request to list the trashbin content
	 *
	 * @param string $user user
	 * @param string $asUser - To send request as another user
	 * @param string $password
	 *
	 * @return void
	 */
	public function sendTrashbinListRequest($user, $asUser = null, $password = null) {
		$asUser = $asUser ?? $user;
		$password = $password ?? $this->featureContext->getPasswordForUser($asUser);
		$response = WebDavHelper::propfind(
			$this->featureContext->getBaseUrl(),
			$asUser,
			$password,
			"/trash-bin/$user/",
			[
				'oc:trashbin-original-filename',
				'oc:trashbin-original-location',
				'oc:trashbin-delete-timestamp',
				'd:getlastmodified'
			],
			$this->featureContext->getStepLineRef(),
			1,
			'trash-bin',
			2
		);
		$this->featureContext->setResponse($response);
		$responseXmlObject = HttpRequestHelper::getResponseXml(
			$response,
			__METHOD__
		);
		$this->featureContext->setResponseXmlObject($responseXmlObject);
	}

	/**
	 * @When user :asUser tries to list the trashbin content for user :user
	 *
	 * @param string $asUser
	 * @param string $user
	 *
	 * @return void
	 */
	public function userTriesToListTheTrashbinContentForUser($asUser, $user) {
		$user = $this->featureContext->getActualUsername($user);
		$asUser = $this->featureContext->getActualUsername($asUser);
		$this->sendTrashbinListRequest($user, $asUser);
	}

	/**
	 * @When user :asUser tries to list the trashbin content for user :user using password :password
	 *
	 * @param string $asUser
	 * @param string $user
	 * @param string $password
	 *
	 * @return void
	 */
	public function userTriesToListTheTrashbinContentForUserUsingPassword($asUser, $user, $password) {
		$this->sendTrashbinListRequest($user, $asUser, $password);
	}

	/**
	 * @Then the last webdav response should contain the following elements
	 *
	 * @param TableNode $elements
	 *
	 * @return void
	 */
	public function theLastWebdavResponseShouldContainFollowingElements(TableNode $elements) {
		$files = $this->getTrashbinContentFromResponseXml($this->featureContext->getResponseXmlObject());
		if (!($elements instanceof TableNode)) {
			throw new InvalidArgumentException(
				'$expectedElements has to be an instance of TableNode'
			);
		}
		$elementRows = $elements->getHash();
		foreach ($elementRows as $expectedElement) {
			$found = false;
			$expectedPath = $expectedElement['path'];
			foreach ($files as $file) {
				if (\ltrim($expectedPath, "/") === \ltrim($file['original-location'], "/")) {
					$found = true;
					break;
				}
			}
			Assert::assertTrue($found, "$expectedPath expected to be listed in response but not found");
		}
	}

	/**
	 * @Then the last webdav response should not contain the following elements
	 *
	 * @param TableNode $elements
	 *
	 * @return void
	 */
	public function theLastWebdavResponseShouldNotContainFollowingElements(TableNode $elements) {
		$files = $this->getTrashbinContentFromResponseXml($this->featureContext->getResponseXmlObject());

		// 'user' is also allowed in the table even though it is not used anywhere
		// This for better readability in feature files
		$this->featureContext->verifyTableNodeColumns($elements, ['path'], ['path', 'user']);
		$elementRows = $elements->getHash();
		foreach ($elementRows as $expectedElement) {
			$notFound = true;
			$expectedPath = "/" . \ltrim($expectedElement['path'], "/");
			foreach ($files as $file) {
				// Allow the table of expected elements to have entries that do
				// not have to specify the "implied" leading slash, or have multiple
				// leading slashes, to make scenario outlines more flexible
				if ($expectedPath === $file['original-location']) {
					$notFound = false;
				}
			}
			Assert::assertTrue($notFound, "$expectedPath expected not to be listed in response but found");
		}
	}

	/**
	 * @When user :asUser tries to delete the file with original path :path from the trashbin of user :user using the trashbin API
	 *
	 * @param string $asUser
	 * @param string $path
	 * @param string $user
	 *
	 * @return void
	 */
	public function userTriesToDeleteFromTrashbinOfUser($asUser, $path, $user) {
		$numItemsDeleted = $this->tryToDeleteFileFromTrashbin($user, $path, $asUser);
	}

	/**
	 * @When user :asUser tries to delete the file with original path :path from the trashbin of user :user using the password :password and the trashbin API
	 *
	 * @param string $asUser
	 * @param string $path
	 * @param string $user
	 * @param string $password
	 *
	 * @return void
	 */
	public function userTriesToDeleteFromTrashbinOfUserUsingPassword($asUser, $path, $user, $password) {
		$user = $this->featureContext->getActualUsername($user);
		$asUser = $this->featureContext->getActualUsername($asUser);
		$numItemsDeleted = $this->tryToDeleteFileFromTrashbin($user, $path, $asUser, $password);
	}

	/**
	 * @When user :asUser tries to restore the file with original path :path from the trashbin of user :user using the trashbin API
	 *
	 * @param string $asUser
	 * @param string $path
	 * @param string $user
	 *
	 * @return void
	 */
	public function userTriesToRestoreFromTrashbinOfUser($asUser, $path, $user) {
		$user = $this->featureContext->getActualUsername($user);
		$asUser = $this->featureContext->getActualUsername($asUser);
		$this->restoreElement($user, $path, null, true, $asUser);
	}

	/**
	 * @When user :asUser tries to restore the file with original path :path from the trashbin of user :user using the password :password and the trashbin API
	 *
	 * @param string $asUser
	 * @param string $path
	 * @param string $user
	 * @param string $password
	 *
	 * @return void
	 */
	public function userTriesToRestoreFromTrashbinOfUserUsingPassword($asUser, $path, $user, $password) {
		$asUser = $this->featureContext->getActualUsername($asUser);
		$user = $this->featureContext->getActualUsername($user);
		$this->restoreElement($user, $path, null, true, $asUser, $password);
	}

	/**
	 * converts the trashItemHRef from /<base>/remote.php/dav/trash-bin/<user>/<item_id>/ to /trash-bin/<user>/<item_id>
	 *
	 * @param string $href
	 *
	 * @return string
	 */
	private function convertTrashbinHref($href) {
		$trashItemHRef = \trim($href, '/');
		$trashItemHRef = \strstr($trashItemHRef, '/trash-bin');
		$parts = \explode('/', $trashItemHRef);
		$decodedParts = [];
		foreach ($parts as $part) {
			$decodedParts[] = urldecode($part);
		}
		return '/' . \join('/', $decodedParts);
	}

	/**
	 * @When /^user "([^"]*)" tries to delete the (?:file|folder|entry) with original path "([^"]*)" from the trashbin using the trashbin API$/
	 *
	 * @param string $user
	 * @param string $originalPath
	 * @param string $asUser
	 * @param string $password
	 *
	 * @return int the number of items that were matched and requested for delete
	 */
	public function tryToDeleteFileFromTrashbin($user, $originalPath, $asUser = null, $password = null) {
		$user = $this->featureContext->getActualUsername($user);
		$asUser = $asUser ?? $user;
		$listing = $this->listTrashbinFolder($user, null);
		$originalPath = \trim($originalPath, '/');
		$numItemsDeleted = 0;

		foreach ($listing as $entry) {
			if ($entry['original-location'] === $originalPath) {
				$trashItemHRef = $this->convertTrashbinHref($entry['href']);
				$response = $this->featureContext->makeDavRequest(
					$asUser,
					'DELETE',
					$trashItemHRef,
					[],
					null,
					'trash-bin',
					2,
					false,
					$password
				);
				$this->featureContext->setResponse($response);
				$numItemsDeleted++;
			}
		}

		return $numItemsDeleted;
	}

	/**
	 * @When /^user "([^"]*)" deletes the (?:file|folder|entry) with original path "([^"]*)" from the trashbin using the trashbin API$/
	 *
	 * @param string $user
	 * @param string $originalPath
	 *
	 * @return void
	 */
	public function deleteFileFromTrashbin($user, $originalPath) {
		$numItemsDeleted = $this->tryToDeleteFileFromTrashbin($user, $originalPath);

		Assert::assertEquals(
			1,
			$numItemsDeleted,
			"Expected to delete exactly one item from the trashbin but $numItemsDeleted were deleted"
		);
	}

	/**
	 * @When /^user "([^"]*)" deletes the following (?:files|folders|entries) with original path from the trashbin$/
	 *
	 * @param string $user
	 * @param TableNode $table
	 *
	 * @return void
	 */
	public function deleteFollowingFilesFromTrashbin($user, $table) {
		$this->featureContext->verifyTableNodeColumns($table, ["path"]);
		$paths = $table->getHash();

		foreach ($paths as $path) {
			$this->deleteFileFromTrashbin($user, $path["path"]);

			$this->featureContext->pushToLastStatusCodesArrays();
		}
	}

	/**
	 * @Then /^as "([^"]*)" (?:file|folder|entry) "([^"]*)" should exist in the trashbin$/
	 *
	 * @param string $user
	 * @param string $path
	 *
	 * @return void
	 */
	public function asFileOrFolderExistsInTrash($user, $path) {
		$user = $this->featureContext->getActualUsername($user);
		$path = \trim($path, '/');
		$sections = \explode('/', $path, 2);

		$techPreviewHadToBeEnabled = $this->occContext->enableDAVTechPreview();

		$firstEntry = $this->findFirstTrashedEntry($user, \trim($sections[0], '/'));

		Assert::assertNotNull(
			$firstEntry,
			"The first trash entry was not found while looking for trashbin entry '$path' of user '$user'"
		);

		if (\count($sections) !== 1) {
			// TODO: handle deeper structures
			$listing = $this->listTrashbinFolder($user, \basename(\rtrim($firstEntry['href'], '/')));
		}

		if ($techPreviewHadToBeEnabled) {
			$this->occContext->disableDAVTechPreview();
		}

		// query was on the main element ?
		if (\count($sections) === 1) {
			// already found, return
			return;
		}

		$checkedName = \basename($path);

		$found = false;
		foreach ($listing as $entry) {
			if ($entry['name'] === $checkedName) {
				$found = true;
				break;
			}
		}

		Assert::assertTrue(
			$found,
			__METHOD__
			. " Could not find expected resource '$path' in the trash"
		);
	}

	/**
	 * Function to check if an element is in the trashbin
	 *
	 * @param string $user
	 * @param string $originalPath
	 *
	 * @return bool
	 */
	private function isInTrash($user, $originalPath) {
		$techPreviewHadToBeEnabled = $this->occContext->enableDAVTechPreview();
		$res = $this->featureContext->getResponse();
		$listing = $this->listTrashbinFolder($user, null);

		$this->featureContext->setResponse($res);
		if ($techPreviewHadToBeEnabled) {
			$this->occContext->disableDAVTechPreview();
		}
		$originalPath = \trim($originalPath, '/');

		foreach ($listing as $entry) {
			if ($entry['original-location'] === $originalPath) {
				return true;
			}
		}
		return false;
	}

	/**
	 * @param string $user
	 * @param string $trashItemHRef
	 * @param string $destinationPath
	 * @param string $asUser - To send request as another user
	 * @param string $password
	 *
	 * @return ResponseInterface
	 */
	private function sendUndeleteRequest($user, $trashItemHRef, $destinationPath, $asUser = null, $password = null) {
		$asUser = $asUser ?? $user;
		$destinationPath = \trim($destinationPath, '/');
		$destinationValue = $this->featureContext->getBaseUrl() . "/remote.php/dav/files/$user/$destinationPath";

		$trashItemHRef = $this->convertTrashbinHref($trashItemHRef);
		$headers['Destination'] = $destinationValue;
		$response = $this->featureContext->makeDavRequest(
			$asUser,
			'MOVE',
			$trashItemHRef,
			$headers,
			null,
			'trash-bin',
			2,
			false,
			$password
		);
		$this->featureContext->setResponse($response);
		return $response;
	}

	/**
	 * @param string $user
	 * @param string $originalPath
	 * @param string $destinationPath
	 * @param bool $throwExceptionIfNotFound
	 * @param string $asUser - To send request as another user
	 * @param string $password
	 *
	 * @return ResponseInterface|null
	 * @throws Exception
	 */
	private function restoreElement($user, $originalPath, $destinationPath = null, $throwExceptionIfNotFound = true, $asUser = null, $password = null) {
		$asUser = $asUser ?? $user;
		$listing = $this->listTrashbinFolder($user, null);
		$originalPath = \trim($originalPath, '/');
		if ($destinationPath === null) {
			$destinationPath = $originalPath;
		}
		foreach ($listing as $entry) {
			if ($entry['original-location'] === $originalPath) {
				return $this->sendUndeleteRequest(
					$user,
					$entry['href'],
					$destinationPath,
					$asUser,
					$password
				);
			}
		}
		// The requested element to restore was not even in the trashbin.
		// Throw an exception, because there was not any API call, and so there
		// is also no up-to-date response to examine in later test steps.
		if ($throwExceptionIfNotFound) {
			throw new \Exception(
				__METHOD__
				. " cannot restore from trashbin because no element was found for user $user at original path $originalPath"
			);
		}
		return null;
	}

	/**
	 * @When user :user restores the folder with original path :originalPath without specifying the destination using the trashbin API
	 *
	 * @param $user string
	 * @param $originalPath string
	 *
	 * @return ResponseInterface
	 * @throws Exception
	 */
	public function restoreFileWithoutDestination($user, $originalPath) {
		$asUser = $asUser ?? $user;
		$listing = $this->listTrashbinFolder($user, null);
		$originalPath = \trim($originalPath, '/');

		foreach ($listing as $entry) {
			if ($entry['original-location'] === $originalPath) {
				$trashItemHRef = $this->convertTrashbinHref($entry['href']);
				$response = $this->featureContext->makeDavRequest(
					$asUser,
					'MOVE',
					$trashItemHRef,
					[],
					null,
					'trash-bin'
				);
				$this->featureContext->setResponse($response);
				// this gives empty response in ocis
				try {
					$responseXml = HttpRequestHelper::getResponseXml(
						$response,
						__METHOD__
					);
					$this->featureContext->setResponseXmlObject($responseXml);
				} catch (Exception $e) {
				}

				return $response;
			}
		}
		throw new \Exception(
			__METHOD__
			. " cannot restore from trashbin because no element was found for user $user at original path $originalPath"
		);
	}

	/**
	 * @Then /^the content of file "([^"]*)" for user "([^"]*)" if the file is also in the trashbin should be "([^"]*)" otherwise "([^"]*)"$/
	 *
	 * Note: this is a special step for an unusual bug combination.
	 *       Delete it when the bug is fixed and the step is no longer needed.
	 *
	 * @param string $fileName
	 * @param string $user
	 * @param string $content
	 * @param string $alternativeContent
	 *
	 * @return void
	 */
	public function contentOfFileForUserIfAlsoInTrashShouldBeOtherwise(
		$fileName,
		$user,
		$content,
		$alternativeContent
	) {
		$isInTrash = $this->isInTrash($user, $fileName);
		$user = $this->featureContext->getActualUsername($user);
		$this->featureContext->downloadFileAsUserUsingPassword($user, $fileName);
		if ($isInTrash) {
			$this->featureContext->downloadedContentShouldBe($content);
		} else {
			$this->featureContext->downloadedContentShouldBe($alternativeContent);
		}
	}

	/**
	 * @When /^user "([^"]*)" tries to restore the (?:file|folder|entry) with original path "([^"]*)" using the trashbin API$/
	 *
	 * @param string $user
	 * @param string $originalPath
	 *
	 * @return void
	 */
	public function userTriesToRestoreElementInTrash($user, $originalPath) {
		$this->restoreElement($user, $originalPath, null, false);
	}

	/**
	 * @When /^user "([^"]*)" restores the (?:file|folder|entry) with original path "([^"]*)" using the trashbin API$/
	 *
	 * @param string $user
	 * @param string $originalPath
	 *
	 * @return void
	 * @throws Exception
	 */
	public function elementInTrashIsRestored($user, $originalPath) {
		$user = $this->featureContext->getActualUsername($user);
		$this->restoreElement($user, $originalPath);
	}

	/**
	 * @When /^user "([^"]*)" restores the following (?:files|folders|entries) with original path$/
	 *
	 * @param string $user
	 * @param TableNode $table
	 *
	 * @return void
	 * @throws Exception
	 */
	public function userRestoresFollowingFiles($user, TableNode $table) {
		$this->featureContext->verifyTableNodeColumns($table, ["path"]);
		$paths = $table->getHash();

		foreach ($paths as $originalPath) {
			$this->elementInTrashIsRestored($user, $originalPath["path"]);

			$this->featureContext->pushToLastStatusCodesArrays();
		}
	}

	/**
	 * @Given /^user "([^"]*)" has restored the (?:file|folder|entry) with original path "([^"]*)"$/
	 *
	 * @param string $user
	 * @param string $originalPath
	 *
	 * @return void
	 * @throws Exception
	 */
	public function elementInTrashHasBeenRestored($user, $originalPath) {
		$this->restoreElement($user, $originalPath);
		if ($this->isInTrash($user, $originalPath)) {
			throw new Exception("File previously located at $originalPath is still in the trashbin");
		}
	}

	/**
	 * @When /^user "([^"]*)" restores the (?:file|folder|entry) with original path "([^"]*)" to "([^"]*)" using the trashbin API$/
	 *
	 * @param string $user
	 * @param string $originalPath
	 * @param string $destinationPath
	 *
	 * @return void
	 */
	public function userRestoresTheFileWithOriginalPathToUsingTheTrashbinApi(
		$user,
		$originalPath,
		$destinationPath
	) {
		$user = $this->featureContext->getActualUsername($user);
		$this->restoreElement($user, $originalPath, $destinationPath);
	}

	/**
	 * @Then /^as "([^"]*)" the (?:file|folder|entry) with original path "([^"]*)" should exist in the trashbin$/
	 *
	 * @param string $user
	 * @param string $originalPath
	 *
	 * @return void
	 */
	public function elementIsInTrashCheckingOriginalPath(
		$user,
		$originalPath
	) {
		$user = $this->featureContext->getActualUsername($user);
		Assert::assertTrue(
			$this->isInTrash($user, $originalPath),
			"File previously located at $originalPath wasn't found in the trashbin"
		);
	}

	/**
	 * @Then /^as "([^"]*)" the (?:file|folder|entry) with original path "([^"]*)" should not exist in the trashbin/
	 *
	 * @param string $user
	 * @param string $originalPath
	 *
	 * @return void
	 */
	public function elementIsNotInTrashCheckingOriginalPath(
		$user,
		$originalPath
	) {
		$user = $this->featureContext->getActualUsername($user);
		Assert::assertFalse(
			$this->isInTrash($user, $originalPath),
			"File previously located at $originalPath was found in the trashbin"
		);
	}

	/**
	 * @Then /^as "([^"]*)" the (?:files|folders|entries) with following original paths should not exist in the trashbin$/
	 *
	 * @param string $user
	 * @param TableNode $table
	 *
	 * @return void
	 */
	public function followingElementsAreNotInTrashCheckingOriginalPath(
		$user,
		TableNode $table
	) {
		$this->featureContext->verifyTableNodeColumns($table, ["path"]);
		$paths = $table->getHash($table);

		foreach ($paths as $originalPath) {
			$this->elementIsNotInTrashCheckingOriginalPath($user, $originalPath["path"]);
		}
	}

	/**
	 * @Then /^as "([^"]*)" the (?:files|folders|entries) with following original paths should exist in the trashbin$/
	 *
	 * @param string $user
	 * @param TableNode $table
	 *
	 * @return void
	 */
	public function followingElementsAreInTrashCheckingOriginalPath(
		$user,
		TableNode $table
	) {
		$this->featureContext->verifyTableNodeColumns($table, ["path"]);
		$paths = $table->getHash($table);

		foreach ($paths as $originalPath) {
			$this->elementIsInTrashCheckingOriginalPath($user, $originalPath["path"]);
		}
	}

	/**
	 * Finds the first trashed entry matching the given name
	 *
	 * @param string $user
	 * @param string $name
	 *
	 * @return string|null real entry name with timestamp suffix or null if not found
	 */
	private function findFirstTrashedEntry($user, $name) {
		$listing = $this->listTrashbinFolder($user, '/');

		foreach ($listing as $entry) {
			if ($entry['name'] === $name) {
				return $entry;
			}
		}

		return null;
	}

	/**
	 * This will run before EVERY scenario.
	 * It will set the properties for this object.
	 *
	 * @BeforeScenario
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
		$this->occContext = $environment->getContext('OccContext');
	}

	/**
	 * @Then /^the deleted (?:file|folder) "([^"]*)" should have the correct deletion mtime in the response$/
	 *
	 * @param string $resource file or folder in trashbin
	 *
	 * @return void
	 */
	public function theDeletedFileFolderShouldHaveCorrectDeletionMtimeInTheResponse($resource) {
		$files = $this->getTrashbinContentFromResponseXml(
			$this->featureContext->getResponseXmlObject()
		);

		$found = false;
		$expectedMtime = $this->featureContext->getLastUploadDeleteTime();
		$responseMtime = '';

		foreach ($files as $file) {
			if (\ltrim($resource, "/") === \ltrim($file['original-location'], "/")) {
				$responseMtime = $file['mtime'];
				$mtime_difference = \abs((int)\trim($expectedMtime) - (int)\trim($responseMtime));

				if ($mtime_difference <= 2) {
					$found = true;
					break;
				}
			}
		}
		Assert::assertTrue(
			$found,
			"$resource expected to be listed in response with mtime '$expectedMtime' but found '$responseMtime'"
		);
	}

	/**
	 * @Given the administrator has set the following file extensions to be skipped from the trashbin
	 *
	 * @param TableNode $table
	 *
	 * @return void
	 */
	public function theAdministratorHasSetFollowingFileExtensionsToBeSkippedFromTrashbin(TableNode $table) {
		$this->featureContext->verifyTableNodeColumns($table, ['extension']);
		foreach ($table->getHash() as $idx => $row) {
			$this->featureContext->runOcc(['config:system:set', 'trashbin_skip_extensions', $idx, '--value=' . $row['extension']]);
		}
	}

	/**
	 * @Given the administrator has set the following directories to be skipped from the trashbin
	 *
	 * @param TableNode $table
	 *
	 * @return void
	 */
	public function theAdministratorHasSetFollowingDirectoriesToBeSkippedFromTrashbin(TableNode $table) {
		$this->featureContext->verifyTableNodeColumns($table, ['directory']);
		foreach ($table->getHash() as $idx => $row) {
			$this->featureContext->runOcc(['config:system:set', 'trashbin_skip_directories', $idx, '--value=' . $row['directory']]);
		}
	}

	/**
	 * @Given the administrator has set the trashbin skip size threshold to :threshold
	 *
	 * @param string $threshold
	 *
	 * @return void
	 */
	public function theAdministratorHasSetTrashbinSkipSizethreshold($threshold) {
		$this->featureContext->runOcc(['config:system:set', 'trashbin_skip_size_threshold', '--value=' . $threshold]);
	}
}
