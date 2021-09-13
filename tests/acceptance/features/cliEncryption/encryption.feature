@cli
Feature: encryption command
  As an admin
  I want to encrypt-decrypt my data
  So that users' files are protected

  Background:
    Given the administrator has invoked occ command "encryption:enable"
    And the administrator has selected master key encryption type using the occ command


  Scenario: view current encryption status
    When the administrator invokes occ command "encryption:status"
    Then the command should have been successful
    And the command output should contain the text "enabled: true"
    And the command output should contain the text "defaultModule: OC_DEFAULT_MODULE"


  Scenario: list available encryption modules
    When the administrator invokes occ command "encryption:list-modules"
    Then the command should have been successful
    And the command output should contain the text "OC_DEFAULT_MODULE: Default encryption module [default*]"


  Scenario: show current key storage root
    When the administrator invokes occ command "encryption:show-key-storage-root"
    Then the command should have been successful
    And the command output should contain the text "Current key storage root:  default storage location (data/)"


  Scenario: move encryption keys to a different folder
    When the administrator invokes occ command "encryption:change-key-storage-root owncloud-keys"
    Then the command should have been successful
    When the administrator invokes occ command "encryption:show-key-storage-root"
    And the command output should contain the text "Current key storage root:  owncloud-keys"

  Scenario: data file contents should be encrypted
    Given user "Alice" has been created with default attributes and without skeleton files
    When user "Alice" uploads file with content "file to upload" to "/fileToUpload.txt" using the WebDAV API
    Then file "fileToUpload.txt" of user "Alice" should be encrypted


  Scenario: downloaded content of an uploaded should not be encrypted
    Given user "brand-new-user" has been created with default attributes and without skeleton files
    When user "brand-new-user" uploads file with content "uploaded content" to "fileToUpload.txt" using the WebDAV API
    And the content of file "fileToUpload.txt" for user "brand-new-user" should be "uploaded content"


  Scenario: should not be possible to disable encryption without decrypting encrypted files
    When the administrator invokes occ command "encryption:disable"
    Then the command should have failed with exit code 1
    And the command output should contain the text "The system still have encrypted files. Please decrypt them all before disabling encryption."

  @skipOnOcV10 @issue-encryption-303
  Scenario: should be possible to disable encryption after decrypting all encrypted files
    When the administrator runs encryption decrypt all using the occ command
    And the administrator invokes occ command "encryption:disable"
    Then the command should have been successful
    And the command output should contain the text "Cleaned up config"
    And the command output should contain the text "Encryption is already disabled"
    When the administrator invokes occ command "encryption:status"
    Then the command should have been successful
    And the command output should contain the text "enabled: false"


