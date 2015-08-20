<?php

/**
 * ownCloud - Activities App
 *
 * @author Frank Karlitschek, Joas Schilling
 * @copyright 2013 Frank Karlitschek frank@owncloud.org
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU AFFERO GENERAL PUBLIC LICENSE
 * License as published by the Free Software Foundation; either
 * version 3 of the License, or any later version.
 *
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU AFFERO GENERAL PUBLIC LICENSE for more details.
 *
 * You should have received a copy of the GNU Affero General Public
 * License along with this library.  If not, see <http://www.gnu.org/licenses/>.
 */

namespace OCA\Activity;

use OC\Files\Filesystem;
use OC\Files\View;
use OCA\Activity\Extension\Files;
use OCA\Activity\Extension\Files_Sharing;
use OCP\Activity\IExtension;
use OCP\Activity\IManager;
use OCP\IDBConnection;
use OCP\Share;
use OCP\Util;

/**
 * The class to handle the filesystem hooks
 */
class FilesHooks {

	/** @var \OCP\Activity\IManager */
	protected $manager;

	/** @var \OCA\Activity\Data */
	protected $activityData;

	/** @var \OCA\Activity\UserSettings */
	protected $userSettings;

	/** @var \OCP\IDBConnection */
	protected $connection;

	/** @var string|false */
	protected $currentUser;

	/**
	 * Constructor
	 *
	 * @param IManager $manager
	 * @param Data $activityData
	 * @param UserSettings $userSettings
	 * @param IDBConnection $connection
	 * @param string|false $currentUser
	 */
	public function __construct(IManager $manager, Data $activityData, UserSettings $userSettings, IDBConnection $connection, $currentUser) {
		$this->manager = $manager;
		$this->activityData = $activityData;
		$this->userSettings = $userSettings;
		$this->connection = $connection;
		$this->currentUser = $currentUser;
	}

	/**
	 * Store the create hook events
	 * @param array $params The hook params
	 */
	public function fileCreate($params) {
		if ($this->currentUser !== false) {
			$this->addNotificationsForFileAction($params['path'], Files::TYPE_SHARE_CREATED, 'created_self', 'created_by');
		} else {
			$this->addNotificationsForFileAction($params['path'], Files::TYPE_SHARE_CREATED, '', 'created_public');
		}
	}

	/**
	 * Store the update hook events
	 * @param array $params The hook params
	 */
	public function fileUpdate($params) {
		$this->addNotificationsForFileAction($params['path'], Files::TYPE_SHARE_CHANGED, 'changed_self', 'changed_by');
	}

	/**
	 * Store the delete hook events
	 * @param array $params The hook params
	 */
	public function fileDelete($params) {
		$this->addNotificationsForFileAction($params['path'], Files::TYPE_SHARE_DELETED, 'deleted_self', 'deleted_by');
	}

	/**
	 * Store the restore hook events
	 * @param array $params The hook params
	 */
	public function fileRestore($params) {
		$this->addNotificationsForFileAction($params['filePath'], Files::TYPE_SHARE_RESTORED, 'restored_self', 'restored_by');
	}

	/**
	 * Creates the entries for file actions on $file_path
	 *
	 * @param string $filePath         The file that is being changed
	 * @param int    $activityType     The activity type
	 * @param string $subject          The subject for the actor
	 * @param string $subjectBy        The subject for other users (with "by $actor")
	 */
	protected function addNotificationsForFileAction($filePath, $activityType, $subject, $subjectBy) {
		// Do not add activities for .part-files
		if (substr($filePath, -5) === '.part') {
			return;
		}

		list($filePath, $uidOwner, $fileId) = $this->getSourcePathAndOwner($filePath);
		$affectedUsers = $this->getUserPathsFromPath($filePath, $uidOwner);
		$filteredStreamUsers = $this->userSettings->filterUsersBySetting(array_keys($affectedUsers), 'stream', $activityType);
		$filteredEmailUsers = $this->userSettings->filterUsersBySetting(array_keys($affectedUsers), 'email', $activityType);

		foreach ($affectedUsers as $user => $path) {
			if (empty($filteredStreamUsers[$user]) && empty($filteredEmailUsers[$user])) {
				continue;
			}

			if ($user === $this->currentUser) {
				$userSubject = $subject;
				$userParams = array($path);
			} else {
				$userSubject = $subjectBy;
				$userParams = array($path, $this->currentUser);
			}

			$this->addNotificationsForUser(
				$user, $userSubject, $userParams,
				$fileId, $path, true,
				!empty($filteredStreamUsers[$user]),
				!empty($filteredEmailUsers[$user]) ? $filteredEmailUsers[$user] : 0,
				$activityType
			);
		}
	}

	/**
	 * Returns a "username => path" map for all affected users
	 *
	 * @param string $path
	 * @param string $uidOwner
	 * @return array
	 */
	protected function getUserPathsFromPath($path, $uidOwner) {
		return Share::getUsersSharingFile($path, $uidOwner, true, true);
	}

	/**
	 * Return the source
	 *
	 * @param string $path
	 * @return array
	 */
	protected function getSourcePathAndOwner($path) {
		$uidOwner = Filesystem::getOwner($path);
		$fileId = 0;

		if ($uidOwner !== $this->currentUser) {
			Filesystem::initMountPoints($uidOwner);
		}
		$info = Filesystem::getFileInfo($path);
		if ($info !== false) {
			$ownerView = new View('/' . $uidOwner . '/files');
			$fileId = (int) $info['fileid'];
			$path = $ownerView->getPath($fileId);
		}

		return array($path, $uidOwner, $fileId);
	}

	/**
	 * Manage sharing events
	 * @param array $params The hook params
	 */
	public function share($params) {
		if ($params['itemType'] === 'file' || $params['itemType'] === 'folder') {
			if ($params['shareWith']) {
				if ((int) $params['shareType'] === Share::SHARE_TYPE_USER) {
					$this->shareFileOrFolderWithUser($params);
				} else if ((int) $params['shareType'] === Share::SHARE_TYPE_GROUP) {
					$this->shareFileOrFolderWithGroup($params);
				}
			} else {
				$this->shareFileOrFolder($params);
			}
		}
	}

	/**
	 * Sharing a file or folder with a user
	 * @param array $params The hook params
	 */
	protected function shareFileOrFolderWithUser($params) {
		// User performing the share
		$this->shareNotificationForSharer('shared_user_self', $params['shareWith'], (int) $params['fileSource'], $params['itemType']);

		// New shared user
		$path = $params['fileTarget'];
		$this->addNotificationsForUser(
			$params['shareWith'], 'shared_with_by', array($path, $this->currentUser),
			(int) $params['fileSource'], $path, ($params['itemType'] === 'file'),
			$this->userSettings->getUserSetting($params['shareWith'], 'stream', Files_Sharing::TYPE_SHARED),
			$this->userSettings->getUserSetting($params['shareWith'], 'email', Files_Sharing::TYPE_SHARED) ? $this->userSettings->getUserSetting($params['shareWith'], 'setting', 'batchtime') : 0
		);
	}

	/**
	 * Sharing a file or folder with a group
	 * @param array $params The hook params
	 */
	protected function shareFileOrFolderWithGroup($params) {
		// User performing the share
		$this->shareNotificationForSharer('shared_group_self', $params['shareWith'], (int) $params['fileSource'], $params['itemType']);

		// Members of the new group
		$affectedUsers = array();
		$usersInGroup = \OC_Group::usersInGroup($params['shareWith']);
		foreach ($usersInGroup as $user) {
			$affectedUsers[$user] = $params['fileTarget'];
		}

		// Remove the triggering user, we already managed his notifications
		unset($affectedUsers[$this->currentUser]);

		if (empty($affectedUsers)) {
			return;
		}

		$filteredStreamUsersInGroup = $this->userSettings->filterUsersBySetting($usersInGroup, 'stream', Files_Sharing::TYPE_SHARED);
		$filteredEmailUsersInGroup = $this->userSettings->filterUsersBySetting($usersInGroup, 'email', Files_Sharing::TYPE_SHARED);

		// Check when there was a naming conflict and the target is different
		// for some of the users
		$query = $this->connection->executeQuery('SELECT `share_with`, `file_target` FROM `*PREFIX*share` WHERE `parent` = ? ', [(int) $params['id']]);
		while ($row = $query->fetch()) {
			$affectedUsers[$row['share_with']] = $row['file_target'];
		}

		foreach ($affectedUsers as $user => $path) {
			if (empty($filteredStreamUsersInGroup[$user]) && empty($filteredEmailUsersInGroup[$user])) {
				continue;
			}

			$this->addNotificationsForUser(
				$user, 'shared_with_by', array($path, $this->currentUser),
				(int) $params['fileSource'], $path, ($params['itemType'] === 'file'),
				!empty($filteredStreamUsersInGroup[$user]),
				!empty($filteredEmailUsersInGroup[$user]) ? $filteredEmailUsersInGroup[$user] : 0
			);
		}
	}

	/**
	 * Add notifications for the user that shares a file/folder
	 *
	 * @param string $subject
	 * @param string $shareWith
	 * @param int $fileSource
	 * @param string $itemType
	 */
	protected function shareNotificationForSharer($subject, $shareWith, $fileSource, $itemType) {
		// User performing the share
		$filePath = Filesystem::getPath($fileSource);

		$this->addNotificationsForUser(
			$this->currentUser, $subject, array($filePath, $shareWith),
			$fileSource, $filePath, ($itemType === 'file'),
			$this->userSettings->getUserSetting($this->currentUser, 'stream', Files_Sharing::TYPE_SHARED),
			$this->userSettings->getUserSetting($this->currentUser, 'email', Files_Sharing::TYPE_SHARED) ? $this->userSettings->getUserSetting($this->currentUser, 'setting', 'batchtime') : 0
		);
	}

	/**
	 * Adds the activity and email for a user when the settings require it
	 *
	 * @param string $user
	 * @param string $subject
	 * @param array $subjectParams
	 * @param int $fileId
	 * @param string $path
	 * @param bool $isFile If the item is a file, we link to the parent directory
	 * @param bool $streamSetting
	 * @param int $emailSetting
	 * @param string $type
	 */
	protected function addNotificationsForUser($user, $subject, $subjectParams, $fileId, $path, $isFile, $streamSetting, $emailSetting, $type = Files_Sharing::TYPE_SHARED) {
		if (!$streamSetting && !$emailSetting) {
			return;
		}

		$selfAction = $user === $this->currentUser;
		$app = $type === Files_Sharing::TYPE_SHARED ? 'files_sharing' : 'files';
		$link = Util::linkToAbsolute('files', 'index.php', array(
			'dir' => ($isFile) ? dirname($path) : $path,
		));

		$objectType = ($fileId) ? 'files' : '';

		$event = $this->manager->generateEvent();
		$event->setApp($app)
			->setType($type)
			->setAffectedUser($user)
			->setAuthor($this->currentUser)
			->setTimestamp(time())
			->setSubject($subject, $subjectParams)
			->setObject($objectType, $fileId, $path)
			->setLink($link);

		// Add activity to stream
		if ($streamSetting && (!$selfAction || $this->userSettings->getUserSetting($this->currentUser, 'setting', 'self'))) {
			$this->activityData->send($event);
		}

		// Add activity to mail queue
		if ($emailSetting && (!$selfAction || $this->userSettings->getUserSetting($this->currentUser, 'setting', 'selfemail'))) {
			$latestSend = time() + $emailSetting;
			$this->activityData->storeMail($event, $latestSend);
		}
	}

	/**
	 * Sharing a file or folder via link/public
	 * @param array $params The hook params
	 */
	protected function shareFileOrFolder($params) {
		$path = Filesystem::getPath($params['fileSource']);

		$this->addNotificationsForUser(
			$this->currentUser, 'shared_link_self', array($path),
			(int) $params['fileSource'], $path, ($params['itemType'] === 'file'),
			$this->userSettings->getUserSetting($this->currentUser, 'stream', Files_Sharing::TYPE_SHARED),
			$this->userSettings->getUserSetting($this->currentUser, 'email', Files_Sharing::TYPE_SHARED) ? $this->userSettings->getUserSetting($this->currentUser, 'setting', 'batchtime') : 0
		);
	}
}
