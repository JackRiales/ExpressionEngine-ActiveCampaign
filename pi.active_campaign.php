
<?php if (!defined('BASEPATH')) exit('No direct script access allowed');

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
		'pi_version'	=>	'1.0',
		'pi_author'		=>	'Jack Riales',
		'pi_author_url'	=>	'http://bluefishds.com/',
		'pi_description'=>	'Takes input XML from a template and performs ActiveCampaign tasks using it.',
		'pi_usage'		=>	Active_Campaign::usage()
		);
	###################

	# Load ActiveCampaign API
	require(PATH_THIRD."/active_campaign/lib/vendor/autoload.php");

	class Active_Campaign {

		protected $api_url;
		protected $api_key;
		protected $ac_connect;
		protected $tag_data;
		protected $return_data;

		#################################################################

		public function __construct() {

			# Feed data inbetween tags (or from the 'data' attribute)
			$this->tag_data = (ee()->TMPL->fetch_param('data')) ? ee()->TMPL->fetch_param('data') : ee()->TMPL->tagdata;

		}

		#################################################################

		public function insert_contacts() {

			# Get ActiveCampaign API info from parameters
			$this->api_url = (ee()->TMPL->fetch_param('url')) ? ee()->TMPL->fetch_param('url') : null;

			$this->api_key = (ee()->TMPL->fetch_param('key')) ? ee()->TMPL->fetch_param('key') : null;

			# If either is missing, break.
			if (!isset($this->api_url)) {
				return "ActiveCampaign URL not found.<br>Please make sure you input your API url by inserting 'url={your url}'.";
			}

			if (!isset($this->api_key)) {
				return "ActiveCampaign Key not found.<br>Please make sure you input your API key by inserting 'key={your key}'.";
			}
			
			# Otherwise, attempt to connect with AC
			$this->ac_connect = new ActiveCampaign($this->api_url, $this->api_key);

			# Exception Check
			if (!$this->ac_connect) {
				return "Unable to connect with ActiveCampaign.";
			}

			# Detect if the user wants debug
			$ac_connect = (ee()->TMPL->fetch_param('debug')) ? ee()->TMPL->fetch_param('debug') : false;

			# If tag data is not set, cannot continue.
			if (!isset($this->tag_data)) {
				return "Tag data not set.";
			}

			# If XML is not valid, cannot continue.
			if (!$this->_xmlValidate($this->tag_data)) {
				return "XML not validated.";
			}

			# If all is well, parse it
			$member_xml = simplexml_load_string($this->tag_data);

			# Error check parse
			if (!$member_xml || empty($member_xml)) {
				$errors = libxml_get_errors();
			    print_r($errors);
			    libxml_clear_errors();
			}

			# Iterate through children
			foreach($member_xml->children() as $child) {
				# Generate JSON in the format AC wants
				$user_json = '{
					"email":"'.$child->email.'",
					"first_name":"'.$child->fname.'",
					"last_name":"'.$child->lname.'",
					"field[%ADDRESS%,0]": "'.$child->primeaddress.'",
					"field[%ADDRESS_2%,0]": "'.$child->auxaddress.'",
					"field[%CITY%,0]": "'.$child->city.'",
					"field[%DESCRIPTION%,0]": "'.$child->description.'",
					"field[%FACEBOOK%,0]": "'.$child->facebook.'",
					"field[%FIRST_NAME%,0]": "'.$child->fname.'",
					"field[%LAST_NAME%,0]": "'.$child->lname.'",
					"field[%LINKED_IN%,0]": "'.$child->linkedin.'",
					"field[%STATE%,0]": "'.$child->state.'",
					"field[%TWITTER%,0]": "'.$child->twitter.'",
					"field[%WEBSITE%,0]": "'.$child->website.'",
					"field[%ZIP%,0]": "'.$child->zip.'"
				}';

				# Decode it as user data
				$user_data = get_object_vars(json_decode($user_json));

				# Submit request and generate response
				$response = $this->ac_connect->api("contact/add", $user_data);

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
					echo $response->error;
				}
			}
		}

		#################################################################

		public static function usage() {
			ob_start();
			?>

			EEActiveCampaign takes ExpressionEngine pre-processed XML data and parses it into the Active Campaign API. For now, it only inserts contacts!

			===

			How to use:

			At the top of the XML area, before using channel entry tags, emplace

				{exp:active_campaign:insert_contacts url="Your Active Campaign URL" key="Your Key" [debug="true|false"]}

			The XML must be validated and must be in the following format:

				<email> Email </email>
				<fname> fname </fname>
				<lname> lname </lname>
				<business> business </business>
				<primeaddress> primeaddress </primeaddress>
				<auxaddress> auxaddress </auxaddress>
				<city> city </city>
				<state> state </state>
				<zip> zip </zip>
				<phone> phone </phone>
				<website> website </website>
				<facebook> facebook </facebook>
				<twitter> twitter </twitter>
				<linkedin> linkedin </linkedin>
				<description> description </description>

			<?php
			$buffer = ob_get_contents();
			ob_end_clean();
			return $buffer;
		}

		#################################################################

		protected function _xmlValidate( $xml ) {
			libxml_use_internal_errors(true);
			$doc = new DOMDocument('1.0', 'utf-8');
			$doc->loadXML( $xml );
			$errors = libxml_get_errors();
			return empty($errors);
		}
	}

?>