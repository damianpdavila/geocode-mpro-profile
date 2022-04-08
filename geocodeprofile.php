<?php
/**
 * @package        Joomla
 * @subpackage     Membership Pro
 * @author         Damian Davila
 * @copyright      Copyright (C) 2022 Moventis, LLC
 * @license        GNU/GPL, see LICENSE.php
 */
defined('_JEXEC') or die;

class plgOSMembershipGeocodeProfile extends JPlugin
{
	/**
	 * Database object.
	 *
	 * @var    JDatabaseDriver
	 */
	protected $db;

	/**
	 * Run when a membership activated
	 *
	 * @param OSMembershipTableSubscriber $row
	 */
	public function onAfterStoreSubscription($row)
	{
		if (!$row->user_id)
		{
			return;
		}

		// Require library + register autoloader
		//require_once JPATH_ADMINISTRATOR . '/components/com_osmembership/loader.php';

		$userId = $row->user_id;

		if ($this->params->get('add_geo_coding_to_profile'))
		{
			$this->geocodeSubscriber($userId, $this->db);
		}

	}

	/**
	 * Plugin triggered when user update his profile
	 *
	 * @param OSMembershipTableSubscriber $row The subscription record
	 */
	public function onProfileUpdate($row)
	{
		$this->onAfterStoreSubscription($row);
	}

	/**
	 * Plugin triggered when user update his profile
	 *
	 * @param OSMembershipTableSubscriber $row The subscription record
	 */
	public function onMembershipUpdate($row)
	{
		$this->onAfterStoreSubscription($row);
	}

	/**
	 * Get latitude and longitude for given subscriber and store values in custom profile fields if successful.
	 *
	 * @param $userId
	 * @param $db     
	 *
	 * @return bool
	 */
	protected function geocodeSubscriber($userId, $db)
	{

		$query = $db->getQuery(true);

		$query->select( $db->quoteName( array('id', 'user_id', 'address', 'address2', 'city', 'state', 'zip', 'country')))
			->from($db->quoteName('#__osmembership_subscribers'))
			->where($db->quoteName('user_id') . ' = ' . (int) $userId)
			->order($db->quoteName('id') . ' DESC');
		$db->setQuery($query);

		$profileData = $db->loadObjectList();

		if ($profileData)
		{
			foreach ($profileData as $member) 
			{

				if ( ($member->country && $member->city) || ($member->city && $member->state) )
				{
					$address = $member->address.' '.$member->address2.' '.$member->city.', '.$member->state.' '.$member->zip.', '.$member->country;
					$prepAddr = str_replace(' ','+',$address);
					$prepAddr = str_replace('#','%23',$prepAddr);  // hash sign breaks the API call; must be encoded
					$apiKey = $this->params->get('map_api_key');
					$geocode = $this->http_get_contents('https://maps.googleapis.com/maps/api/geocode/json?address='.$prepAddr.'&sensor=false&key='.$apiKey);
			
					$output = json_decode($geocode);
							
					if ($output->status == 'OK') {
						//do the update
						$latitude = $output->results[0]->geometry->location->lat;
						$longitude = $output->results[0]->geometry->location->lng;

						if ( $this->hasGeocodeCustomProfileFields($member->id, $db) ) 
						{
						   $this->updateGeocodeCustomProfileFields($member->id, $latitude, $longitude, $db);
						} 
						else 
						{
						   $this->insertGeocodeCustomProfileFields($member->id, $latitude, $longitude, $db);
						}
		 
					}
					else
					{
					   $errmsg = 'Geocoding error: ' . $geocode . 'Member: '. json_encode($member);
					   JLog::add($errmsg, JLog::ERROR, 'jerror');
					}
		
				}

			}

		}
		return true;

	}   

	/** 
	 * Check if member/subscriber already has geocode custom fields
	 * 
	 * @param $id profile id
	 * 
	 * @return bool
	 *
	 */
	function hasGeocodeCustomProfileFields($id, $db) {

		$query = $db
		->getQuery(true)
		->select($db->quoteName('id'))
		->from($db->quoteName('#__osmembership_field_value'))
		->where($db->quoteName('subscriber_id') . ' = ' . $id);
	
		$db->setQuery($query);
	
		try
		{
		$result = $db->loadResult();
	
		$rc = empty($result) ? false : true;
		}
		catch (Exception $e)
		{
		$errmsg = 'Get custom fields error: ' . json_encode($e->getMessage()) . 'ID: '. json_encode($id);
		JLog::add($errmsg, JLog::ERROR, 'jerror');

		$rc = false;
		}
	
		return $rc;
	
	}
 
	/** 
	 * Update custom profile fields latitude and longitude
	 * 
	 * @param $id profile id
	 * @param $lat the updated latitude
	 * @param $lng the updated longitude
	 * @param $db
	 * 
	 * @return bool
	 *
	 */
	protected function updateGeocodeCustomProfileFields($id, $lat, $lng, $db)
	{
		$query = $db->getQuery(true);

		// Conditions for which records should be updated.
		$conditionsLat = array(
			$db->quoteName('field_id') . ' = 15', 
			$db->quoteName('subscriber_id') . ' = ' . $id
		);
		$conditionsLng = array(
			$db->quoteName('field_id') . ' = 16', 
			$db->quoteName('subscriber_id') . ' = ' . $id
		);

		try
		{
		$query->clear()
			->update($db->quoteName('#__osmembership_field_value'))
			->set($db->quoteName('field_value') . ' = ' . $lat)
			->where($conditionsLat);
			$db->setQuery($query);
		$db->execute();

		$query->clear()
				->update($db->quoteName('#__osmembership_field_value'))
				->set($db->quoteName('field_value') . ' = ' . $lng)
				->where($conditionsLng);
			$db->setQuery($query);
		$db->execute();
		}
		catch (Exception $e)
		{
			$errmsg = 'Update latitude failed: ' . json_encode($e->getMessage()) . 'ID: '. json_encode($id);
			JLog::add($errmsg, JLog::ERROR, 'jerror');
		}
   

		return true;

	}
	/** 
	 * Insert new custom profile fields latitude and longitude
	 * 
	 * @param $id profile id
	 * @param $lat the updated latitude
	 * @param $lng the updated longitude
	 * 
	 * @return bool
	 *
	 */
	function insertGeocodeCustomProfileFields($id, $lat, $lng, $db) {

		$customField = new stdClass();
	
		$customField->field_id='15';
		$customField->field_value=$lat;
		$customField->subscriber_id=$id;   
		try
		{
			$result = $db->insertObject('#__osmembership_field_value', $customField);
		}
		catch (Exception $e)
		{
			$errmsg = 'Insert latitude failed: ' . json_encode($e->getMessage()) . 'ID: '. json_encode($id);
			JLog::add($errmsg, JLog::ERROR, 'jerror');
		}
	
		$customField = new stdClass();
	
		$customField->field_id='16';
		$customField->field_value=$lng;
		$customField->subscriber_id=$id;   
		try
		{
			$result = $db->insertObject('#__osmembership_field_value', $customField);
		}
		catch (Exception $e)
		{
			$errmsg = 'Insert longitude failed: ' . json_encode($e->getMessage()) . 'ID: '. json_encode($id);
			JLog::add($errmsg, JLog::ERROR, 'jerror');
		}
	
		return true;
	
	}
 
	/**
	 * Basic CURL helper function
	 * 
	 * @param $url
	 * @param $opts[]
	 * 
	 * @return data or error from curl
	 */
	protected function http_get_contents($url, Array $opts = []) 
	{
		$ch = curl_init();
		if(!isset($opts[CURLOPT_TIMEOUT])) {
			curl_setopt($ch, CURLOPT_TIMEOUT, 5);
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		if(is_array($opts) && $opts) {
			foreach($opts as $key => $val) {
				curl_setopt($ch, $key, $val);
			}
		}
		if(!isset($opts[CURLOPT_USERAGENT])) {
			curl_setopt($ch, CURLOPT_USERAGENT, $_SERVER['SERVER_NAME']);
		}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
		if(FALSE === ($retval = curl_exec($ch))) {
			error_log(curl_error($ch));
		}
		return $retval;
	}

}
