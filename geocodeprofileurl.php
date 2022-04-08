<?php 
/**
 * @package        Joomla
 * @subpackage     Membership Pro
 * @author         Damian Davila
 * @copyright      Copyright (C) 2022 Moventis, LLC
 * @license        GNU/GPL, see LICENSE.php
 * 
 * Geocodes member profiles from the Membership Pro component.  
 * Accepts Joomla userid as parameter, and geocodes all Membership Pro profiles associated with that userid.
 * Geocoding is done with Google Maps Places API, and stored as latitude and longitude in custom profile fields.
 * 
 */

define( '_JEXEC', 1 );
define('JPATH_BASE', dirname((__FILE__)));

use Joomla\CMS\Factory;

define( 'DS', DIRECTORY_SEPARATOR );

require_once ( JPATH_BASE .DS.'includes'.DS.'defines.php' );
require_once ( JPATH_BASE .DS.'includes'.DS.'framework.php' );

JDEBUG ? $_PROFILER->mark( 'afterLoad' ) : null;

$mainframe =& Factory::getApplication('site');
$mainframe->initialise();
defined('_JEXEC') or die;  

$db    = Factory::getDbo();

$app = Factory::getApplication();
$input = $app->input;

$apiKey = $input->get('apikey', '', 'STRING');
if (! $apiKey) 
{
   die('<h1>Error, no API key was specified.</h1>');
}

$userId = $input->get('userid', '', 'INTEGER');
if ($userId) 
{
   $rc = geocodeSubscriber($userId, $db, $apiKey);
   echo '<br/>Geocodesubscriber complete. RC:' . json_encode($rc);
}
else 
{
   die('<h1>Error, no userid was specified.</h1>');
}


/**
 * Get latitude and longitude for given subscriber and store values in custom profile fields if successful.
 *
 * @param $userId
 *
 * @return bool
 */
function geocodeSubscriber($userId, $db, $apiKey)
{

   $query = $db->getQuery(true);

   $query->select( $db->quoteName( array('id', 'user_id', 'address', 'address2', 'city', 'state', 'zip', 'country')))
      ->from($db->quoteName('#__osmembership_subscribers'))
      ->where($db->quoteName('user_id') . ' = ' . (int) $userId)
      ->order($db->quoteName('id') . ' DESC');
   $db->setQuery($query);

   $profileData = $db->loadObjectList();
   echo json_encode($profileData) . '<br/>';     

   if ($profileData)
   {
      foreach ($profileData as $member) 
      {
         echo json_encode($member) . '<br/>';

         if ( ($member->country && $member->city) || ($member->city && $member->state) )
         {
            $address = $member->address.' '.$member->address2.' '.$member->city.', '.$member->state.' '.$member->zip.', '.$member->country;
            $prepAddr = str_replace(' ','+',$address);
            $prepAddr = str_replace('#','%23',$prepAddr);  // hash sign breaks the API call; must be encoded
            $geocode = http_get_contents('https://maps.googleapis.com/maps/api/geocode/json?address='.$prepAddr.'&sensor=false&key='.$apiKey);

            echo $geocode . '<br/>';

            $output = json_decode($geocode);

            $latitude = $output->results[0]->geometry->location->lat;
            $longitude = $output->results[0]->geometry->location->lng;
                      
            if ($output->status == 'OK') 
            {
               if ( hasGeocodeCustomProfileFields($member->id, $db) ) 
               {
                  echo 'Has geo, updating.  Id: '. $member->id;
                  updateGeocodeCustomProfileFields($member->id, $latitude, $longitude, $db);
               } 
               else 
               {
                  echo 'No geo, inserting.  Id: '. $member->id;
                  insertGeocodeCustomProfileFields($member->id, $latitude, $longitude, $db);
               }
            }
            else
            {
               echo '<br/>API key: ' . $apiKey . '<br/>Member: '. json_encode($member);
               die('<br/>Geocoding error: ' . $geocode);
            }
         }

      }

   }
   else 
   {
      die('Profile not found');
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

      echo 'hasGeo result: '. json_encode($result);

      $rc = empty($result) ? false : true;
   }
   catch (Exception $e)
   {
      echo $e->getMessage();
      echo $db->getQuery()->dump();
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
 * 
 * @return bool
 *
 */
function updateGeocodeCustomProfileFields($id, $lat, $lng, $db) {

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

   $query->clear()
      ->update($db->quoteName('#__osmembership_field_value'))
      ->set($db->quoteName('field_value') . ' = ' . $lat)
      ->where($conditionsLat);
   $db->setQuery($query);

   try
   {
      $db->execute();
   }
   catch (Exception $e)
   {
      echo $e->getMessage();
      echo $db->getQuery()->dump();
      die('Update lat failed.');
   }
   
   echo 'Update lat. cond: ' . json_encode($conditionsLat) . ' lat: ' . $lat . ' rc: '. $rc;

   $query->clear()
      ->update($db->quoteName('#__osmembership_field_value'))
      ->set($db->quoteName('field_value') . ' = ' . $lng)
      ->where($conditionsLng);
   $db->setQuery($query);

   try
   {
      $db->execute();
   }
   catch (Exception $e)
   {
      echo $e->getMessage();
      echo $db->getQuery()->dump();
      die('Update lng failed.');
   }
   
   echo 'Update lng. cond: ' . json_encode($conditionsLng) . ' lng: ' . $lng . ' rc: '. $rc;

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
      echo 'Lat insert: '.$result;
   }
   catch (Exception $e)
   {
      echo $e->getMessage();
      echo $db->getQuery()->dump();
      die('Insert lat failed.');
   }

   $customField = new stdClass();

   $customField->field_id='16';
   $customField->field_value=$lng;
   $customField->subscriber_id=$id;   
   try
   {
      $result = $db->insertObject('#__osmembership_field_value', $customField);
      echo 'Lng insert: '.$result;
   }
   catch (Exception $e)
   {
      echo $e->getMessage();
      echo $db->getQuery()->dump();
      die('Insert lng failed');
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
function http_get_contents($url, Array $opts = [])
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
