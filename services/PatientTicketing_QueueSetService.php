<?php
/**
 * OpenEyes
 *
 * (C) Moorfields Eye Hospital NHS Foundation Trust, 2008-2011
 * (C) OpenEyes Foundation, 2011-2014
 * This file is part of OpenEyes.
 * OpenEyes is free software: you can redistribute it and/or modify it under the terms of the GNU General Public License as published by the Free Software Foundation, either version 3 of the License, or (at your option) any later version.
 * OpenEyes is distributed in the hope that it will be useful, but WITHOUT ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for more details.
 * You should have received a copy of the GNU General Public License along with OpenEyes in a file titled COPYING. If not, see <http://www.gnu.org/licenses/>.
 *
 * @package OpenEyes
 * @link http://www.openeyes.org.uk
 * @author OpenEyes <info@openeyes.org.uk>
 * @copyright Copyright (c) 2008-2011, Moorfields Eye Hospital NHS Foundation Trust
 * @copyright Copyright (c) 2011-2014, OpenEyes Foundation
 * @license http://www.gnu.org/licenses/gpl-3.0.html The GNU General Public License V3.0
 */

namespace OEModule\PatientTicketing\services;
use OEModule\PatientTicketing\models;
use Yii;

class PatientTicketing_QueueSetService  extends \services\ModelService {

	public static $QUEUE_SERVICE = 'PatientTicketing_Queue';

	static protected $operations = array(self::OP_READ, self::OP_SEARCH, self::OP_DELETE);
	static protected $primary_model = 'OEModule\PatientTicketing\models\QueueSet';

	public function search(array $params)
	{
		$model = $this->getSearchModel();
		if (isset($params['id'])) $model->id = $params['id'];

		$searchParams = array('pageSize' => null);
		if (isset($params['name'])) $searchParams['name'] = $params['name'];

		return $this->getResourcesFromDataProvider($model->search($searchParams));
	}

	/**
	 * Pass through wrapper to generate QueueSet Resource
	 *
	 * @param OEModule\PatientTicketing\models\QueueSet $queueset
	 * @return Resource
	 */
	public function modelToResource($queueset)
	{
		$res = parent::modelToResource($queueset);
		foreach (array('name', 'description', 'active') as $pass_thru) {
			$res->$pass_thru = $queueset->$pass_thru;
		}

		$qsvc = Yii::app()->service->getService(self::$QUEUE_SERVICE);

		if ($queueset->initial_queue_id) {
			$res->initial_queue = $qsvc->read($queueset->initial_queue_id);
		}

		if ($queueset->permissioned_users) {
			foreach ($queueset->permissioned_users as $u) {
				$res->permissioned_user_ids[] = $u->id;
			}
		}

		return $res;
	}

	/**
	 * Get all the queue set resources that are part of the given category
	 *
	 * @param PatientTicketing_QueueSetCategory $qscr
	 * @return array
	 */
	public function getQueueSetsForCategory(PatientTicketing_QueueSetCategory $qscr)
	{
		$class = self::$primary_model;
		$criteria = new \CDbCriteria();
		$criteria->addColumnCondition(array('category_id' => $qscr->getId()));
		$res = array();
		foreach ($class::model()->active()->with('permissioned_users')->findAll($criteria) as $qs) {
			$res[] = $this->modelToResource($qs);
		}
		return $res;
	}

	/**
	 * @param PatientTicketing_QueueSet $qsr
	 * @param $user_id
	 * @param bool $include_closing
	 * @return PatientTicketing_Queue[]
	 * @todo: return resources instead of models
	 */
	public function getQueueSetQueues(PatientTicketing_QueueSet $qsr, $user_id, $include_closing = true)
	{
		if ($this->isQueueSetPermissionedForUser($qsr, $user_id)) {
			$q_svc = Yii::app()->service->getService(self::$QUEUE_SERVICE);
			$initial_qr = $qsr->initial_queue;
			$res = array($q_svc->readModel($initial_qr->getId()));
			foreach ($q_svc->getDependentQueues($initial_qr, $include_closing) as $d_qr) {
				$res[] = $d_qr;
			};

			return $res;
		}
		return array();
	}

	/**
	 * Returns the roles configured to allow processing of queue sets
	 *
	 * @return array
	 */
	public function getQueueSetRoles()
	{
		$res = array();
		// iterate through roles and pick out those that have the operation as a child
		foreach (Yii::app()->authManager->getAuthItems(2) as $role) {
			if ($role->hasChild('TaskProcessQueueSet'))  {
				$res[] = $role->name;
			}
		}
		return $res;
	}
	/**
	 * @param integer $queueset_id
	 * @param integer $user_ids[]
	 *
	 * @throws \Exception
	 */
	public function setPermisssionedUsers($queueset_id, $user_ids, $role = null)
	{
		$qs = $this->readModel($queueset_id);
		$users = array();
		foreach ($user_ids as $id) {
			if (!$user = \User::model()->findByPk($id)) {
				throw new \Exception("User not found for id {$id}");
			}
			$users[] = $user;
		}

		$role_item = null;
		if ($role) {
			$role_item = Yii::app()->authManager->getAuthItem($role);
			if (!$role_item) {
				throw new \Exception("Unrecognised role {$role} for permissioning");
			}
		}

		$transaction = Yii::app()->db->getCurrentTransaction() === null
				? Yii::app()->db->beginTransaction()
				: false;

		try {
			$qs->permissioned_users = $users;
			$qs->save();

			if ($role_item) {
				foreach ($users as $user) {
					if (!$role_item->getAssignment($user->id)) {
						$role_item->assign($user->id);
					}
				}
			}

			if ($transaction) {
				$transaction->commit();
			}
		}
		catch (\Exception $e) {
			if ($transaction) {
				$transaction->rollback();
			}
			throw $e;
		}
	}

	/**
	 * @param PatientTicketing_QueueSet $qsr
	 * @param $user_id
	 * @return bool
	 */
	public function isQueueSetPermissionedForUser(PatientTicketing_QueueSet $qsr, $user_id)
	{
		return Yii::app()->getAuthManager()->checkAccess('OprnProcessQueueSet', $user_id, array($user_id, $qsr));
	}

	/**
	 * @param $ticket_id
	 * @return PatientTicketing_QueueSet
	 */
	public function getQueueSetForTicket($ticket_id)
	{
		$t = models\Ticket::model()->findByPk($ticket_id);
		return $this->modelToResource($this->model->findByAttributes(array('initial_queue_id' => $t->initial_queue->id)));
	}

	/**
	 * @param int $queue_id
	 * @return PatientTicketing_QueueSet
	 */
	public function getQueueSetForQueue($queue_id)
	{
		$q_svc = Yii::app()->service->getService(self::$QUEUE_SERVICE);
		$root = $q_svc->getRootQueue($queue_id);
		return $this->modelToResource($this->model->findByAttributes(array('initial_queue_id' => $root->id)));
	}
}