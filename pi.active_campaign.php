<?php if (!defined('BASEPATH')) ee()->output->fatal_error('No direct script access allowed');

/**
* 	ActiveCampaign Class
*
* 	@package	ExpressionEngine
* 	@category	Plugin
*	@author 	Jack Riales
*	@copyright	Copyright (c) 2015, Blue Fish Design Studio LLC
*	@link 		http://bluefishds.com/
*/

### PLUGIN INFO ###
$plugin_info = array(
	'pi_name'		=>	'Active Campaign',
	'pi_version'	=>	'1.1',
	'pi_author'		=>	'Jack Riales',
	'pi_author_url'	=>	'http://bluefishds.com/',
	'pi_description'=>	'Takes input XML from a template and performs ActiveCampaign tasks using it.',
	'pi_usage'		=>	Active_Campaign::usage()
	);
###################

# Load ActiveCampaign API
require(PATH_THIRD."/active_campaign/lib/vendor/autoload.php");

class Active_Campaign {

	# API URL for Authentication
	protected $api_url;

	# API Key for Authentication
	protected $api_key;

	# API connection object
	protected $ac_connect;

	# Data defined between the ExpressionEngine tags
	protected $tag_data;

	# Parsed XML data from the tagdata
	protected $parsed_xml;

	# Whether or not to return debug values
	protected $debug;

	#################################################################

	/**
	*	Active_Campaign Constructor
	*
	*	Performs the following tasks:
	*		- Fetches the ExpressionEngine tag parameters ('url' and 'key') as well as the data between the tags (or defined by the 'data' parameter, but who would do that?)
	*		- Performs error checking to ensure these requirements
	*		- Connects to the ActiveCampaign API, and determines if debug should be used
	*		- Parses the tagdata xml and ensures it is valid.
	*/
	public function __construct() {

		# Feed data inbetween tags (or from the 'data' attribute)
		$this->tag_data = (ee()->TMPL->fetch_param('data')) ? ee()->TMPL->fetch_param('data') : ee()->TMPL->tagdata;

		# Print plaintext tagdata
		print "Input XML:<br><textarea style='width:50%;height:250px'>".$this->tag_data."</textarea><br>";

		# If tag data is not set, cannot continue.
		if (!isset($this->tag_data) || $this->tag_data == "") {
			ee()->output->fatal_error("Tag data not set.");
		}

		# Get ActiveCampaign API info from parameters
		$this->api_url = (ee()->TMPL->fetch_param('url')) ? ee()->TMPL->fetch_param('url') : null;
		$this->api_key = (ee()->TMPL->fetch_param('key')) ? ee()->TMPL->fetch_param('key') : null;

		# If either is missing, break.
		if (!isset($this->api_url)) {
			ee()->output->fatal_error("ActiveCampaign URL not found.<br>Please make sure you input your API url by inserting 'url={your url}'.");
		}

		if (!isset($this->api_key)) {
			ee()->output->fatal_error("ActiveCampaign Key not found.<br>Please make sure you input your API key by inserting 'key={your key}'.");
		}
		
		# Otherwise, attempt to connect with AC
		$this->ac_connect = new ActiveCampaign($this->api_url, $this->api_key);

		# Exception Check
		if (!$this->ac_connect) {
			ee()->output->fatal_error("Unable to connect with ActiveCampaign.");
		}

		# Detect if the user wants debug
		$this->ac_connect->debug = (ee()->TMPL->fetch_param('debug')) ? ee()->TMPL->fetch_param('debug') : false;
		$this->debug = $this->ac_connect->debug;

		# If XML is not valid, cannot continue.
		if (!$this->_xmlValidate($this->tag_data)) {
			print "Problem Tag Data:<br>".$this->tag_data."<br>";
			ee()->output->fatal_error("XML not validated.");
		}

		# If all is well, parse it
		$this->parsed_xml = simplexml_load_string($this->tag_data);

		# Error check parse
		if (!$this->parsed_xml) {
			$errors = libxml_get_errors();
		    print_r($errors);
		    print "<br>";
		    libxml_clear_errors();
		    ee()->output->fatal_error("Errors occured during XML parsing. Cannot continue. Please check your XML formatting.");
		}
	}

	#################################################################

	/**
	*	Active Campaign API Call Method
	*	
	*	Calls API on given request with given JSON input and returns a response from the AC server.
	*
	*	@param (Request) The request type to send to the server. Obtained from ExpressionEngine parameter "request"
	*	@param (Data) The user data to send with the request. Formatting is strict, see bottom of this document for details. Obtained from ExpressionEngine parameter "json"
	*	@return (API Response)
	*/
	public function api_call_input($request = null, $data = null) {

		# Get the request parameter or throw an exception
		$request = (ee()->TMPL->fetch_param('request')) ? ee()->TMPL->fetch_param('request') : null;
		if (!isset($request)) { print "Request parameter not found. Cannot continue."; return null; }

		# Get the data parameter.
		$data = (ee()->TMPL->fetch_param('json')) ? ee()->TMPL->fetch_param('json') : null;
		if (!isset($data)) { print "JSON parameter not found. Cannot continue."; return null; }

		# Begin iterating through XML generated children
		foreach($this->parsed_xml->children() as $child) {
			# Begin parsing the user JSON keys
			$json_keys = explode('&', $data);

			# Start an array of XML elements
			$xml_elements = array();
			foreach($child->children() as $elements) {
				$xml_elements[] = $elements;
			}

			# Begin forming the input string
			$json_input = '{';

			# Check if the number of JSON keys matches the number of elements. Report if so.
			if ($this->debug && count($json_keys) !== count($xml_elements)) {
				print ("Warning: Key count does not equal child count. This may not work as intended.<br>");
				
				print "Printing keys--<br>";
				foreach($json_keys as $key) { print $key."<br>"; }

				print "<br>Printing Elements--<br>";
				foreach($xml_elements as $element) { print $element."<br>"; }
			}

			# For each key, add a new section to the input.
			for($i = 0; $i < count($json_keys); $i++) {
				$json_input .= '"' .$json_keys[$i]. '":"' .$xml_elements[$i]. '",';
			}

			# Trim off the last comma
			$json_input = rtrim($json_input, ',');

			# Finish JSON input string
			$json_input .= '}';

			# Debug print the JSON string
			if ($this->debug) { print "Final JSON export: ".$json_input."<br>"; }

			# Decode the json into how the API wants it
			$json_decode = get_object_vars(json_decode($json_input));

			# Give the request and generate a response value
			$response = $this->ac_connect->api($request, $json_decode);

			# <debug>
			if ($this->debug) {
				if ((int)$response->success) {
					// Request succeeded. Generate items.
					$items = array();
					foreach($response as $key => $value) {
						if (is_int($key)) {
							$items[] = $value;
						}
					}

					if (count($items) == 20) {
						// Fetch next page
					}
					
				} else {
					// Request error.
					print "<br>Request did not succeed. Response error as follow:<br>".$response->error."<br>";
				}
				print "<br>Next Request<br><br>";
			}
			# </debug>

			print "Request made: ";
			if ((int)$response->success) { print "Response returned success!<br>"; }
			else { print "Response returned failure. You may want to try using the debug parameter.<br>"; }
		}
	}

	#################################################################

	protected function _xmlValidate( $xml ) {
		libxml_use_internal_errors(true);
		$doc = new DOMDocument('1.0', 'utf-8');
		$doc->loadXML( $xml );
		$errors = libxml_get_errors();
		return empty($errors);
	}

	#################################################################

	public static function usage() {
		ob_start();
		?>

		This plugin takes ExpressionEngine pre-processed XML data and parses it into the Active Campaign API.
		As of this version, only POST api calls are useable.

		=============================================

		**** TEMPLATE USAGE ****

		At the top of the XML area, before using channel entry tags, emplace

			{exp:active_campaign:api_call_input
				url="Your Active Campaign URL" 
				key="Your Key"
				request="Your request (see bottom)"
				json="Your JSON data (see bottom)"
				[debug="true|false"]}

		This will be followed by your XML data. It may look something like this:
		<root>
			{exp:channel:entries channel="people" dynamic="off"}
			<thing>
				<email>{p_email}</email>
				<fname>{p_fname}</fname>
				<lname>{p_lname}</lname>
			</thing>
			{/exp:channel:entries}
		</root>

		**** REQUESTING ****

		Request calls are strings that Active Campaign recognizes. The string will determine which function on their side is called. Here are a few examples:

		"contact/add", "campaign/report/unsubscription/totals", "automation/list"

		Some of these require inline parameters, some do not. Some (ones that are 'putting' data) require JSON input, others do not. Here is API documentation to consider:
		http://www.activecampaign.com/api/overview.php

		**** JSON ****

		When pushing data to Active Campaign, rather than receiving data, they require JSON formatted input. Most of this JSON will be generated for you using the XML data, but the keys must be provided by you. On the front-end, if you wanted to add users to a list on ActiveCampaign, you would put something like this:

		json="email&tags[0]"

		Then, your XML would look like this:

		<root>
			{exp:channel:entries channel="people" dynamic="off"}
			<thing>
				<email>{p_email}</email>
				<tag>ListToAddTo</tag>
			</thing>
			{/exp:channel:entries}
		</root>

		This would tell the script to input to the API:

		'{
		"email": {whatever p_email is},
		"tags[0]": "ListToAddTo"
		}'

		Note that the amp (&) symbol seperates the different keys.

		!!! The order of the tags in the json parameter MUST MATCH the order of tags in the XML.

		**** RUNNING ****

		The final template will look something similar to this:

		{exp:active_campaign:api_call_input 
		url="url" 
		key="key" 
		request="contact/add" 
		json="email&first_name&last_name&field[%FIRST_NAME%,0]&field[%LAST_NAME%,0]&field[%CITY%,0]"}
		<members>
			{exp:channel:entries channel="People" dynamic="off"}
			<member>
				<email>{p_email}</email>
			  	<fname>{fname}</fname>
			  	<lname>{lname}</lname>
			  	<field_fname>{fname}</field_fname>
				<field_lname>{lname}</field_lname>
				<city>{city}</city>
			</member>
			{/exp:channel:entries}
		</members>
		{/exp:active_campaign:api_call_input}

		This is saying to perform contact/add using all the channel entries of channel "people" with the parameters first name, last name, and city.

		Accessing the page is, in essence, running the program.

		**** WARNING ****

		Using this, you are responsible for any mass flooding you do to your ActiveCampaign system. PLEASE read their documentation and be careful of how you use this.

		If you receive PHP warnings about modifying headers, be assured that this is normal. This is due to the fact that this script uses "print" before ExpressionEngine may attempt to display the page.

		=============================================

		<?php
		$buffer = ob_get_contents();
		ob_end_clean();
		return $buffer;
	}
}

# EOD

?>