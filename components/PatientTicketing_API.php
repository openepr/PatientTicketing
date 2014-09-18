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

namespace OEModule\PatientTicketing\components;

use OEModule\PatientTicketing\models\QueueSetCategory;
use OEModule\PatientTicketing\models\Queue;
use OEModule\PatientTicketing\models\Ticket;
use Yii;

class PatientTicketing_API extends \BaseAPI
{

	public static $TICKET_SUMMARY_WIDGET = 'OEModule\PatientTicketing\widgets\TicketSummary';
	public static $QUEUE_ASSIGNMENT_WIDGET = 'OEModule\PatientTicketing\widgets\QueueAssign';
	public static $QUEUESETCATEGORY_SERVICE = 'PatientTicketing_QueueSetCategory';

	public function getMenuItems($position = 1)
	{
		$result = array();

		$qsc_svc = Yii::app()->service->getService(self::$QUEUESETCATEGORY_SERVICE);
		$user = Yii::app()->user;
		foreach ($qsc_svc->getCategoriesForUser($user->id) as $qsc) {
			$result[] = array(
					'uri' => '/PatientTicketing/default/?cat_id='.$qsc->id,
					'title' => $qsc->name,
					'position' => $position++,
			);
		};

		return $result;
	}

	/**
	 * Simple function to standardise access to the retrieving the Queue Assignment Form
	 *
	 * @return string
	 */
	public function getQueueAssignmentFormURI()
	{
		return "/PatientTicketing/Default/GetQueueAssignmentForm/";
	}

	/**
	 * @param $event
	 * @return mixed
	 */
	public function getTicketForEvent($event)
	{
		if ($event->id) {
			return Ticket::model()->findByAttributes(array('event_id' => $event->id));
		}
	}

	/**
	 * Filters and purifies passed array to get data relevant to a ticket queue assignment
	 *
	 * @param \OEModule\PatientTicketing\models\Queue $queue
	 * @param $data
	 * @param bool $validate
	 * @return array
	 */
	public function extractQueueData(Queue $queue, $data, $validate = false)
	{
		$res = array();
		$errs = array();
		$p = new \CHtmlPurifier();

		foreach ($queue->getFormFields() as $field) {
			$field_name = $field['form_name'];
			$res[$field_name] = $p->purify(@$data[$field_name]);
			if ($validate) {
				if ($field['required'] && !@$data[$field_name]) {
					$errs[$field_name] = $field['label'] . " is required";
				}
				elseif (@$field['choices'] && @$data[$field_name]) {
					$match = false;
					foreach ($field['choices'] as $k => $v) {
						if ($data[$field_name] == $k) {
							$match = true;
							break;
						}
					}
					if (!$match) {
						$errs[$field_name] = $field['label'] .": invalid choice";
					}
				}
			}
		}

		if ($validate) {
			return array($res, $errs);
		}
		else {
			return $res;
		}
	}

	/**
	 *
	 * @param \Event $event
	 * @param Queue $initial_queue
	 * @param \CWebUser $user
	 * @param \Firm $firm
	 * @param $data
	 * @throws \Exception
	 * @return \OEModule\PatientTicketing\models\Ticket
	 */
	public function createTicketForEvent(\Event $event, Queue $initial_queue, \CWebUser $user, \Firm $firm, $data)
	{
		$patient = $event->episode->patient;
		if ($ticket = $this->createTicketForPatient($patient, $initial_queue, $user, $firm, $data)) {
			$ticket->event_id = $event->id;
			$ticket->save();
		}
		else {
			throw new \Exception('Ticket was not created for an unknown reason');
		}

		return $ticket;
	}

	/**
	 * @param \Patient $patient
	 * @param Queue $initial_queue
	 * @param \CWebUser $user
	 * @param \Firm $firm
	 * @param $data
	 * @throws \Exception
	 * @return \OEModule\PatientTicketing\models\Ticket
	 */
	public function createTicketForPatient(\Patient $patient, Queue $initial_queue, \CWebUser $user, \Firm $firm, $data)
	{
		$transaction = Yii::app()->db->getCurrentTransaction() === null
				? Yii::app()->db->beginTransaction()
				: false;

		try {
			$ticket = new Ticket();
			$ticket->patient_id = $patient->id;
			$ticket->created_user_id = $user->id;
			$ticket->last_modified_user_id = $user->id;
			$ticket->priority_id = $data['patientticketing__priority'];
			$ticket->save();

			$initial_queue->addTicket($ticket, $user, $firm, $data);
			if ($transaction) {
				$transaction->commit();
			}
			return $ticket;

		}
		catch (\Exception $e) {
			if ($transaction) {
				$transaction->rollback();
			}
			throw $e;
		}
	}

	/**
	 * Verifies that the provided queue id is an id for a Queue that the User can add to as the given Firm
	 * At the moment, no verification takes place beyond the fact that the id is valid and active
	 *
	 * @param \CWebUser $user
	 * @param \Firm $firm
	 * @param integer $id
	 */
	public function getQueueForUserAndFirm(\CWebUser $user, \Firm $firm, $id)
	{
		return Queue::model()->active()->findByPk($id);
	}

	/**
	 * Returns the initial queues a patient ticket can be created against.
	 *
	 * @param \Firm $firm
	 * @return Queue[]
	 */
	public function getInitialQueues(\Firm $firm)
	{
		$criteria = new \CDbCriteria();
		$criteria->addColumnCondition( array('is_initial' => true));
		return Queue::model()->active()->findAll($criteria);
	}

	/**
	 * Returns the Queue Sets a patient ticket can be created in for the given firm.
	 * (Note: firm filtering is not currently implemented)
	 *
	 * @param \Firm $firm
	 * @return mixed
	 */
	public function getQueueSetList(\Firm $firm, \Patient $patient = null)
	{
		$qs_svc = Yii::app()->service->getService("PatientTicketing_QueueSet");
		$res = array();
		foreach ($qs_svc->getQueueSetsForFirm($firm) as $qs_r) {
			if ($patient && $qs_svc->canAddPatientToQueueSet($patient, $qs_r->getId())) {
				$res[$qs_r->initial_queue->getId()] = $qs_r->name;
			}
		}
		return $res;
	}

	/**
	 * @param \Patient $patient
	 * @param Queue $queue
	 * @return mixed
	 */
	public function canAddPatientToQueue(\Patient $patient, Queue $queue)
	{
		$qs_svc = Yii::app()->service->getService("PatientTicketing_QueueSet");
		$qs_r = $qs_svc->getQueueSetForQueue($queue->id);
		return $qs_svc->canAddPatientToQueueSet($patient, $qs_r->getId());
	}
}