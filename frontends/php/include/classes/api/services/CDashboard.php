<?php
/*
** Zabbix
** Copyright (C) 2001-2017 Zabbix SIA
**
** This program is free software; you can redistribute it and/or modify
** it under the terms of the GNU General Public License as published by
** the Free Software Foundation; either version 2 of the License, or
** (at your option) any later version.
**
** This program is distributed in the hope that it will be useful,
** but WITHOUT ANY WARRANTY; without even the implied warranty of
** MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
** GNU General Public License for more details.
**
** You should have received a copy of the GNU General Public License
** along with this program; if not, write to the Free Software
** Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
**/


/**
 * Class containing methods for operations with screen items.
 */
class CDashboard extends CApiService {

	const MAX_ROW = 63;
	const MAX_COL = 11;

	protected $tableName = 'widget';
	protected $tableAlias = 'w';

	protected $sortColumns = [
		'screenitemid',
		'screenid'
	];

	public function __construct() {
		parent::__construct();

		$this->getOptions = zbx_array_merge($this->getOptions, [
			'screenitemids'	=> null,
			'screenids'		=> null,
			'editable'		=> null,
			'sortfield'		=> '',
			'sortorder'		=> '',
			'preservekeys'	=> null,
			'countOutput'	=> null
		]);
	}

	/**
	 * Get screem item data.
	 *
	 * @param array $options
	 * @param array $options['screenitemids']	Search by screen item IDs
	 * @param array $options['screenids']		Search by screen IDs
	 * @param array $options['filter']			Result filter
	 * @param array $options['limit']			The size of the result set
	 *
	 * @return array
	 */
	public function get(array $options = []) {
		$options = zbx_array_merge($this->getOptions, $options);

		// build and execute query
		$sql = $this->createSelectQuery($this->tableName(), $options);
		$res = DBselect($sql, $options['limit']);

		// fetch results
		$result = [];
		while ($row = DBfetch($res)) {
			// count query, return a single result
			if ($options['countOutput'] !== null) {
				$result = $row['rowscount'];
			}
			// normal select query
			else {
				if ($options['preservekeys'] !== null) {
					$result[$row['screenitemid']] = $row;
				}
				else {
					$result[] = $row;
				}
			}
		}

		return $result;
	}

	/**
	 * @param array $dashboards
	 *
	 * @return array
	 */
	public function create(array $dashboards) {
		$this->validateCreate($dashboards);

		$ins_dashboards = [];

		foreach ($dashboards as $dashboard) {
			unset($dashboard['users'], $dashboard['userGroups'], $dashboard['widgets']);
			$ins_dashboards[] = $dashboard;
		}

		$dashboardids = DB::insert('dashboard', $ins_dashboards);

		foreach ($dashboards as $index => &$dashboard) {
			$dashboard['dashboardid'] = $dashboardids[$index];
		}
		unset($dashboard);

		$this->updateDashboardUser($dashboards, __FUNCTION__);
		$this->updateDashboardUsrgrp($dashboards, __FUNCTION__);
		$this->updateWidget($dashboards, __FUNCTION__);

		$this->addAuditBulk(AUDIT_ACTION_ADD, AUDIT_RESOURCE_DASHBOARD, $dashboards);

		return ['dashboardids' => $dashboardids];
	}

	/**
	 * @param array $dashboards
	 *
	 * @throws APIException if the input is invalid
	 */
	private function validateCreate(array &$dashboards) {
		$api_input_rules = ['type' => API_OBJECTS, 'flags' => API_NOT_EMPTY | API_NORMALIZE, 'uniq' => [['name']], 'fields' => [
			'name' =>			['type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('dashboard', 'name')],
			'userid' =>			['type' => API_ID, 'default' => self::$userData['userid']],
			'private' =>		['type' => API_INT32, 'in' => implode(',', [PUBLIC_SHARING, PRIVATE_SHARING])],
			'users' =>			['type' => API_OBJECTS, 'fields' => [
				'userid' =>			['type' => API_ID, 'flags' => API_REQUIRED],
				'permission' =>		['type' => API_INT32, 'flags' => API_REQUIRED, 'in' => implode(',', [PERM_READ, PERM_READ_WRITE])]
			]],
			'userGroups' =>		['type' => API_OBJECTS, 'fields' => [
				'usrgrpid' =>		['type' => API_ID, 'flags' => API_REQUIRED],
				'permission' =>		['type' => API_INT32, 'flags' => API_REQUIRED, 'in' => implode(',', [PERM_READ, PERM_READ_WRITE])]
			]],
			'widgets' =>		['type' => API_OBJECTS, 'fields' => [
				'type' =>			['type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('widget', 'type')],
				'name' =>			['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('widget', 'name'), 'default' => DB::getDefault('widget', 'name')],
				'row' =>			['type' => API_INT32, 'in' => '0:'.self::MAX_ROW, 'default' => DB::getDefault('widget', 'row')],
				'col' =>			['type' => API_INT32, 'in' => '0:'.self::MAX_COL, 'default' => DB::getDefault('widget', 'col')],
				'height' =>			['type' => API_INT32, 'in' => '1:32', 'default' => DB::getDefault('widget', 'height')],
				'width' =>			['type' => API_INT32, 'in' => '1:12', 'default' => DB::getDefault('widget', 'width')]
			]]
		]];
		if (!CApiInputValidator::validate($api_input_rules, $dashboards, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		$this->checkDuplicates(zbx_objectValues($dashboards, 'name'));
		$this->checkUsers($dashboards);
		$this->checkUserGroups($dashboards);
		$this->checkDuplicateResourceInCell($dashboards);
	}

	/**
	 * @param array $dashboards
	 *
	 * @return array
	 */
	public function update(array $dashboards) {
		$this->validateUpdate($dashboards, $db_dashboards);

		$upd_dashboards = [];

		foreach ($dashboards as $dashboard) {
			$db_dashboard = $db_dashboards[$dashboard['dashboardid']];

			$upd_dashboard = [];

			if (array_key_exists('name', $dashboard) && $dashboard['name'] !== $db_dashboard['name']) {
				$upd_dashboard['name'] = $dashboard['name'];
			}
			if (array_key_exists('userid', $dashboard) && bccomp($dashboard['userid'], $db_dashboard['userid']) != 0) {
				$upd_dashboard['userid'] = $dashboard['userid'];
			}
			if (array_key_exists('private', $dashboard) && $dashboard['private'] != $db_dashboard['private']) {
				$upd_dashboard['private'] = $dashboard['private'];
			}

			if ($upd_dashboard) {
				$upd_dashboards[] = [
					'values' => $upd_dashboard,
					'where' => ['dashboardid' => $dashboard['dashboardid']]
				];
			}
		}

		if ($upd_dashboards) {
			DB::update('dashboard', $upd_dashboards);
		}

		$this->updateDashboardUser($dashboards, __FUNCTION__);
		$this->updateDashboardUsrgrp($dashboards, __FUNCTION__);
		$this->updateWidget($dashboards, __FUNCTION__);

		$this->addAuditBulk(AUDIT_ACTION_UPDATE, AUDIT_RESOURCE_DASHBOARD, $dashboards, $db_dashboards);

		return ['dashboardids' => zbx_objectValues($dashboards, 'dashboardid')];
	}

	/**
	 * @param array $dashboards
	 * @param array $db_dashboards
	 *
	 * @throws APIException if the input is invalid
	 */
	private function validateUpdate(array &$dashboards, array &$db_dashboards = null) {
		$api_input_rules = ['type' => API_OBJECTS, 'flags' => API_NOT_EMPTY | API_NORMALIZE, 'uniq' => [['dashboardid'], ['name']], 'fields' => [
			'dashboardid' =>	['type' => API_ID, 'flags' => API_REQUIRED],
			'name' =>			['type' => API_STRING_UTF8, 'flags' => API_NOT_EMPTY, 'length' => DB::getFieldLength('dashboard', 'name')],
			'userid' =>			['type' => API_ID],
			'private' =>		['type' => API_INT32, 'in' => implode(',', [PUBLIC_SHARING, PRIVATE_SHARING])],
			'users' =>			['type' => API_OBJECTS, 'fields' => [
				'userid' =>			['type' => API_ID, 'flags' => API_REQUIRED],
				'permission' =>		['type' => API_INT32, 'flags' => API_REQUIRED, 'in' => implode(',', [PERM_READ, PERM_READ_WRITE])]
			]],
			'userGroups' =>		['type' => API_OBJECTS, 'fields' => [
				'usrgrpid' =>		['type' => API_ID, 'flags' => API_REQUIRED],
				'permission' =>		['type' => API_INT32, 'flags' => API_REQUIRED, 'in' => implode(',', [PERM_READ, PERM_READ_WRITE])]
			]],
			'widgets' =>		['type' => API_OBJECTS, 'fields' => [
				'type' =>			['type' => API_STRING_UTF8, 'flags' => API_REQUIRED | API_NOT_EMPTY, 'length' => DB::getFieldLength('widget', 'type')],
				'name' =>			['type' => API_STRING_UTF8, 'length' => DB::getFieldLength('widget', 'name'), 'default' => DB::getDefault('widget', 'name')],
				'row' =>			['type' => API_INT32, 'in' => '0:'.self::MAX_ROW, 'default' => DB::getDefault('widget', 'row')],
				'col' =>			['type' => API_INT32, 'in' => '0:'.self::MAX_COL, 'default' => DB::getDefault('widget', 'col')],
				'height' =>			['type' => API_INT32, 'in' => '1:32', 'default' => DB::getDefault('widget', 'height')],
				'width' =>			['type' => API_INT32, 'in' => '1:12', 'default' => DB::getDefault('widget', 'width')]
			]]
		]];
		if (!CApiInputValidator::validate($api_input_rules, $dashboards, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

		// Check dashboard names.
// TODO:$db_dashboards = API::Dashboard()->get([
		$db_dashboards = DB::select('dashboard', [
			'output' => ['dashboardid', 'name', 'userid', 'private'],
			'dashboardids' => zbx_objectValues($dashboards, 'dashboardid'),
			'preservekeys' => true
		]);

		$names = [];

		foreach ($dashboards as $dashboard) {
			// Check if this dashboard exists.
			if (!array_key_exists($dashboard['dashboardid'], $db_dashboards)) {
				self::exception(ZBX_API_ERROR_PERMISSIONS,
					_('No permissions to referred object or it does not exist!')
				);
			}

			$db_dashboard = $db_dashboards[$dashboard['dashboardid']];

			if (array_key_exists('name', $dashboard) && $dashboard['name'] !== $db_dashboard['name']) {
				$names[] = $dashboard['name'];
			}
		}

		if ($names) {
			$this->checkDuplicates($names);
		}
		$this->checkUsers($dashboards);
		$this->checkUserGroups($dashboards);
		$this->checkDuplicateResourceInCell($dashboards);
	}

	/**
	 * Check for duplicated dashboards.
	 *
	 * @param array  $names
	 *
	 * @throws APIException  if dashboard already exists.
	 */
	private function checkDuplicates(array $names) {
		$db_dashboards = DB::select('dashboard', [
			'output' => ['name'],
			'filter' => ['name' => $names],
			'limit' => 1
		]);

		if ($db_dashboards) {
			self::exception(ZBX_API_ERROR_PARAMETERS,
				_s('Dashboard "%1$s" already exists.', $db_dashboards[0]['name'])
			);
		}
	}

	/**
	 * Check for valid users.
	 *
	 * @param array  $dashboards
	 * @param string $dashboards[]['userid']             (optional)
	 * @param array  $dashboards[]['users']              (optional)
	 * @param string $dashboards[]['users'][]['userid']
	 *
	 * @throws APIException  if user is not valid.
	 */
	private function checkUsers(array $dashboards) {
		$userids = [];

		foreach ($dashboards as $dashboard) {
			if (array_key_exists('userid', $dashboard)) {
				if (bccomp($dashboard['userid'], self::$userData['userid']) != 0
						&& !in_array(self::$userData['type'], [USER_TYPE_ZABBIX_ADMIN, USER_TYPE_SUPER_ADMIN])) {
					self::exception(ZBX_API_ERROR_PARAMETERS, _('Only administrators can set dashboard owner.'));
				}

				$userids[$dashboard['userid']] = true;
			}

			if (array_key_exists('users', $dashboard)) {
				foreach ($dashboard['users'] as $user) {
					$userids[$user['userid']] = true;
				}
			}
		}

		unset($userids[self::$userData['userid']]);

		if (!$userids) {
			return;
		}

		$userids = array_keys($userids);

		$db_users = API::User()->get([
			'output' => [],
			'userids' => $userids,
			'preservekeys' => true
		]);

		foreach ($userids as $userid) {
			if (!array_key_exists($userid, $db_users)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('User with ID "%1$s" is not available.', $userid));
			}
		}
	}

	/**
	 * Check for valid user groups.
	 *
	 * @param array  $dashboards
	 * @param array  $dashboards[]['userGroups']                (optional)
	 * @param string $dashboards[]['userGroups'][]['usrgrpid']
	 *
	 * @throws APIException  if user group is not valid.
	 */
	private function checkUserGroups(array $dashboards) {
		$usrgrpids = [];

		foreach ($dashboards as $dashboard) {
			if (array_key_exists('userGroups', $dashboard)) {
				foreach ($dashboard['userGroups'] as $usrgrp) {
					$usrgrpids[$usrgrp['usrgrpid']] = true;
				}
			}
		}

		if (!$usrgrpids) {
			return;
		}

		$usrgrpids = array_keys($usrgrpids);

		$db_usrgrps = API::UserGroup()->get([
			'output' => [],
			'usrgrpids' => $usrgrpids,
			'preservekeys' => true
		]);

		foreach ($usrgrpids as $usrgrpid) {
			if (!array_key_exists($usrgrpid, $db_usrgrps)) {
				self::exception(ZBX_API_ERROR_PARAMETERS, _s('User group with ID "%1$s" is not available.', $usrgrpid));
			}
		}
	}

	/**
	 * Check duplicates screen items in one cell.
	 *
	 * @param array  $dashboards
	 * @param string $dashboards[]['name']
	 * @param array  $dashboards[]['widgets']
	 * @param int    $dashboards[]['widgets'][]['row']
	 * @param int    $dashboards[]['widgets'][]['col']
	 * @param int    $dashboards[]['widgets'][]['height']
	 * @param int    $dashboards[]['widgets'][]['width']
	 *
	 * @throws APIException if input is invalid.
	 */
	private function checkDuplicateResourceInCell(array $dashboards) {
		foreach ($dashboards as $dashboard) {
			if (array_key_exists('widgets', $dashboard)) {
				$filled = [];

				foreach ($dashboard['widgets'] as $widget) {
					for ($row = $widget['row']; $row < $widget['row'] + $widget['height']; $row++) {
						for ($col = $widget['col']; $col < $widget['col'] + $widget['width']; $col++) {
							if (array_key_exists($row, $filled) && array_key_exists($col, $filled[$row])) {
								self::exception(ZBX_API_ERROR_PARAMETERS,
									_s('Dashboard "%1$s" cell X - %2$s Y - %3$s is already taken.',
										$dashboard['name'], $widget['col'], $widget['row']
									)
								);
							}

							$filled[$row][$col] = true;
						}
					}

					if ($widget['row'] + $widget['height'] - 1 > self::MAX_ROW
							|| $widget['col'] + $widget['width'] - 1 > self::MAX_COL) {
						self::exception(ZBX_API_ERROR_PARAMETERS,
							_s('Dashboard "%1$s" widget in cell X - %2$s Y - %3$s is ouf of bounds.',
								$dashboards[$dashboardid]['name'], $widget['col'], $widget['row']
							)
						);
					}
				}
			}
		}
	}

	/**
	 * Update table "dashboard_user".
	 *
	 * @param array  $dashboards
	 * @param string $method
	 */
	private function updateDashboardUser(array $dashboards, $method) {
		$dashboards_users = [];

		foreach ($dashboards as $dashboard) {
			if (array_key_exists('users', $dashboard)) {
				$dashboards_users[$dashboard['dashboardid']] = [];

				foreach ($dashboard['users'] as $user) {
					$dashboards_users[$dashboard['dashboardid']][$user['userid']] = [
						'permission' => $user['permission']
					];
				}
			}
		}

		if (!$dashboards_users) {
			return;
		}

		$db_dashboard_users = ($method === 'update')
			? DB::select('dashboard_user', [
				'output' => ['dashboard_userid', 'dashboardid', 'userid', 'permission'],
				'filter' => ['dashboardid' => array_keys($dashboards_users)]
			])
			: [];

		$ins_dashboard_users = [];
		$upd_dashboard_users = [];
		$del_dashboard_userids = [];

		foreach ($db_dashboard_users as $db_dashboard_user) {
			$dashboardid = $db_dashboard_user['dashboardid'];
			$userid = $db_dashboard_user['userid'];

			if (array_key_exists($userid, $dashboards_users[$dashboardid])) {
				if ($dashboards_users[$dashboardid][$userid]['permission'] != $db_dashboard_user['permission']) {
					$upd_dashboard_users[] = [
						'values' => ['permission' => $dashboards_users[$dashboardid][$userid]['permission']],
						'where' => ['dashboard_userid' => $db_dashboard_user['dashboard_userid']]
					];
				}

				unset($dashboards_users[$dashboardid][$userid]);
			}
			else {
				$del_dashboard_userids[] = $db_dashboard_user['dashboard_userid'];
			}
		}

		foreach ($dashboards_users as $dashboardid => $users) {
			foreach ($users as $userid => $user) {
				$ins_dashboard_users[] = [
					'dashboardid' => $dashboardid,
					'userid' => $userid,
					'permission' => $user['permission']
				];
			}
		}

		if ($ins_dashboard_users) {
			DB::insertBatch('dashboard_user', $ins_dashboard_users);
		}

		if ($upd_dashboard_users) {
			DB::update('dashboard_user', $upd_dashboard_users);
		}

		if ($del_dashboard_userids) {
			DB::delete('dashboard_user', ['dashboard_userid' => $del_dashboard_userids]);
		}
	}

	/**
	 * Update table "dashboard_usrgrp".
	 *
	 * @param array  $dashboards
	 * @param string $method
	 */
	private function updateDashboardUsrgrp(array $dashboards, $method) {
		$dashboards_usrgrps = [];

		foreach ($dashboards as $dashboard) {
			if (array_key_exists('userGroups', $dashboard)) {
				$dashboards_usrgrps[$dashboard['dashboardid']] = [];

				foreach ($dashboard['userGroups'] as $usrgrp) {
					$dashboards_usrgrps[$dashboard['dashboardid']][$usrgrp['usrgrpid']] = [
						'permission' => $usrgrp['permission']
					];
				}
			}
		}

		if (!$dashboards_usrgrps) {
			return;
		}

		$db_dashboard_usrgrps = ($method === 'update')
			? DB::select('dashboard_usrgrp', [
				'output' => ['dashboard_usrgrpid', 'dashboardid', 'usrgrpid', 'permission'],
				'filter' => ['dashboardid' => array_keys($dashboards_usrgrps)]
			])
			: [];

		$ins_dashboard_usrgrps = [];
		$upd_dashboard_usrgrps = [];
		$del_dashboard_usrgrpids = [];

		foreach ($db_dashboard_usrgrps as $db_dashboard_usrgrp) {
			$dashboardid = $db_dashboard_usrgrp['dashboardid'];
			$usrgrpid = $db_dashboard_usrgrp['usrgrpid'];

			if (array_key_exists($usrgrpid, $dashboards_usrgrps[$dashboardid])) {
				if ($dashboards_usrgrps[$dashboardid][$usrgrpid]['permission'] != $db_dashboard_usrgrp['permission']) {
					$upd_dashboard_usrgrps[] = [
						'values' => ['permission' => $dashboards_usrgrps[$dashboardid][$usrgrpid]['permission']],
						'where' => ['dashboard_usrgrpid' => $db_dashboard_usrgrp['dashboard_usrgrpid']]
					];
				}

				unset($dashboards_usrgrps[$dashboardid][$usrgrpid]);
			}
			else {
				$del_dashboard_usrgrpids[] = $db_dashboard_usrgrp['dashboard_usrgrpid'];
			}
		}

		foreach ($dashboards_usrgrps as $dashboardid => $usrgrps) {
			foreach ($usrgrps as $usrgrpid => $usrgrp) {
				$ins_dashboard_usrgrps[] = [
					'dashboardid' => $dashboardid,
					'usrgrpid' => $usrgrpid,
					'permission' => $usrgrp['permission']
				];
			}
		}

		if ($ins_dashboard_usrgrps) {
			DB::insertBatch('dashboard_usrgrp', $ins_dashboard_usrgrps);
		}

		if ($upd_dashboard_usrgrps) {
			DB::update('dashboard_usrgrp', $upd_dashboard_usrgrps);
		}

		if ($del_dashboard_usrgrpids) {
			DB::delete('dashboard_usrgrp', ['dashboard_usrgrpid' => $del_dashboard_usrgrpids]);
		}
	}

	/**
	 * Update table "widget".
	 *
	 * @param array  $dashboards
	 * @param string $method
	 */
	private function updateWidget(array $dashboards, $method) {
		$dashboard_widgets = [];

		foreach ($dashboards as $dashboard) {
			if (array_key_exists('widgets', $dashboard)) {
				CArrayHelper::sort($dashboard['widgets'], ['row', 'col']);
				$dashboard_widgets[$dashboard['dashboardid']] = $dashboard['widgets'];
			}
		}

		if (!$dashboard_widgets) {
			return;
		}

		$db_widgets = ($method === 'update')
			? DB::select('widget', [
				'output' => ['widgetid', 'dashboardid', 'type', 'name', 'row', 'col', 'height', 'width'],
				'filter' => ['dashboardid' => array_keys($dashboard_widgets)],
				'sortfield' => ['dashboardid', 'row', 'col']
			])
			: [];

		$ins_widgets = [];
		$upd_widgets = [];
		$del_widgetids = [];

		foreach ($db_widgets as $db_widget) {
			if ($dashboard_widgets[$db_widget['dashboardid']]) {
				$widget = array_shift($dashboard_widgets[$db_widget['dashboardid']]);

				$upd_widget = [];

				// strings
				foreach (['type', 'name'] as $field_name) {
					if ($widget[$field_name] !== $db_widget[$field_name]) {
						$upd_widget[$field_name] = $widget[$field_name];
					}
				}
				// integers
				foreach (['row', 'col', 'height', 'width'] as $field_name) {
					if ($widget[$field_name] != $db_widget[$field_name]) {
						$upd_widget[$field_name] = $widget[$field_name];
					}
				}

				if ($upd_widget) {
					$upd_widgets[] = [
						'values' => $upd_widget,
						'where' => ['widgetid' => $db_widget['widgetid']]
					];
				}
			}
			else {
				$del_widgetids[] = $db_widget['widgetid'];
			}
		}

		foreach ($dashboard_widgets as $dashboardid => $widgets) {
			foreach ($widgets as $widget) {
				$ins_widgets[] = ['dashboardid' => $dashboardid] + $widget;
			}
		}

		if ($ins_widgets) {
			DB::insertBatch('widget', $ins_widgets);
		}

		if ($upd_widgets) {
			DB::update('widget', $upd_widgets);
		}

		if ($del_widgetids) {
			DB::delete('widget', ['dashboard_usrgrpid' => $del_widgetids]);
		}
	}

	/**
	 * @param array $dashboardids
	 *
	 * @return array
	 */
	public function delete(array $dashboardids) {
		$api_input_rules = ['type' => API_IDS, 'flags' => API_NOT_EMPTY, 'uniq' => true];
		if (!CApiInputValidator::validate($api_input_rules, $dashboardids, '/', $error)) {
			self::exception(ZBX_API_ERROR_PARAMETERS, $error);
		}

// TODO:$db_dashboards = API::Dashboard()->get([
		$db_dashboards = DB::select('dashboard', [
			'output' => ['dashboardid', 'name'],
			'dashboardids' => $dashboardids,
			'preservekeys' => true
		]);

		foreach ($dashboardids as $dashboardid) {
			if (!array_key_exists($dashboardid, $db_dashboards)) {
				self::exception(ZBX_API_ERROR_PERMISSIONS,
					_('No permissions to referred object or it does not exist!')
				);
			}
		}

		DB::delete('dashboard', ['dashboardid' => $dashboardids]);

		$this->addAuditBulk(AUDIT_ACTION_DELETE, AUDIT_RESOURCE_DASHBOARD, $db_dashboards);

		return ['dashboardids' => $dashboardids];
	}
}
