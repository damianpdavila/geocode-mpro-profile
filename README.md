# geocode-mpro-profile
Geocodes member (subscriber) profiles in Membership Pro Joomla component.  Packaged as a plugin.

This is a companion product to the practitioner-directory repo.  This plugin prepares the Membership Pro profiles to be displayed on the map.

## Features
* Installed as plugin for Membership Pro component.  Triggered when new profile is added or updated.
* Will geocode using Google Places API based on address in the profile.
* The latitude/longitude are stored in custom fields that must be pre-defined on the Membership Pro profile records.
* It will geocode all profiles belonging to the same Joomla userid.  In Membership Pro, a Joomla user may have several Mpro profiles, based on their memberships.
 
### Installation
* Install it as any other Joomla plugin.  One PHP file plus one config XML file, zipped.
* Activate the plugin, edit it, and enter a valid Google Maps Places API key into the config panel.
