<?php

/**
 * Class unbounceDataHandler
 *
 *  Unbounce forms don't collect dates properly to send to InfusionSoft through integration, this takes the form submissions from Unbounce
 *  as an extra POST, creates a short delay, then processes & updates records based on Email address.
 *
 *	$infObj comes from "/infusion/isdk-new/src/isdk.php" ;
 *
 */
class unbounceDataHandler {

	public $infObj, $db_fns, $db_data, $dbConn, $postArr;

	public $limit = 100;

	public $unbounceDateFieldsArr = array();

	protected $attemptCnt = 0;

	public function __construct($db_fns,$db_data,$dbConn_app,$infObj) {
		
		$this->db_fns  = $db_fns;
		$this->db_data = $db_data;
		$this->dbConn  = $dbConn_app;
		$this->infObj = $infObj;

		$finArr = array();

		$jsonTxt = @$_POST['data_json'];
		if ( empty( $jsonTxt ) ) {
			return false;
		}

		$jsonArr = json_decode( $jsonTxt, true );

		foreach ( $jsonArr as $key => $val ) {

			// $key=filter_var($key,FILTER_SANITIZE_STRING);
			$val = $val[0];
			if ( stristr( $key, 'date' ) && ! empty( $val ) ) {
				$this->unbounceDateFieldsArr[] = $key;
			}

			$finArr[ $key ] = $val;
		}

		$this->sendDataToLog( __FUNCTION__ . ': jsonText: ' . "$jsonTxt; \r\njsonArr: " . print_r( $jsonArr, true ) );
		$this->postArr = $finArr;

		if ( count( $this->unbounceDateFieldsArr ) == 0 ) {
			return false;
		}

		return $this->processUnbounceToInfusion();
	}

	public function sendDataToLog( $data ) {

		file_put_contents( $_SERVER['DOCUMENT_ROOT'] . '/libraries/unbounce/process-log.txt', date( "m/d/Y H:i:s" ) . " " . $data . "\r\n", FILE_APPEND );
	}

	public function processUnbounceToInfusion() {
		$res = '';

		if ( $this->infObj->cfgCon( 'InfusionSoftApp' ) ) {

			$infIdArr = $this->searchInfContact( $this->postArr );  // Returns [0] => Array([Id] => XXXXX   [LastName] => SomeonesLastName )

			foreach ( $infIdArr as $infRow ) {

				foreach ( $this->unbounceDateFieldsArr as $unbounceDateField ) {

					$infId = $infRow['Id'];
					$res .= "InfusionSoft searchInfContact response infID#: $infId; ";

					if ( $infId > 0 ) {

						$unbounceDateOrig =
						$unbounceDate = $this->postArr[ $unbounceDateField ];

						if ( empty( $unbounceDate ) ) {
							$res .= "Empty date field, $unbounceDateField;";
						}
						$unbounceDateUnix = strtotime( $unbounceDate );

						if ( $unbounceDateUnix > 1000 ) {
							$unbounceDate = date( "Ymd\TH:i:s", $unbounceDateUnix );

							if ( stristr( $unbounceDateField, 'grad' ) ) {
								$updateArr = array( '_Date1' => $unbounceDate );
							} else {
								$updateArr = array( '_Date2' => $unbounceDate );
							}
							$updateResponse = $this->updateInfContact( $infId, $updateArr );
							$res .= "Inf #$infId: InfusionSoft API response: $updateResponse; ";
						} else {
							$res .= "Invalid date, $unbounceDate, skipping.";
						}

					} else {
						$res .= "Could not connect to InfusionSoft;";
					}
				}

			}

		} else {
			$res .= 'Could not connect it InfusionSoft API; ';
		}

		$this->sendDataToLog( __FUNCTION__ . ': unbounceDates: ' . "$unbounceDateOrig=>$unbounceDate;" . print_r( $res, true ) . "\r\n" );

	}

	/**
	 * @param $postArr
	 *
	 * @return array|bool       $response will be a multi-dim array [0]['Id'], [1]['Id'].... because Infusion may have more than one rec ID with the same email
	 */
	public function searchInfContact( $postArr ) {

		$email        = $postArr['email'];
		$returnFields = array( 'Id', 'LastName' );
		$response     = $this->infObj->findByEmail( $email, $returnFields );

		$this->sendDataToLog( __FUNCTION__ . ': email ' . $email . '; POST:' . print_r( $postArr, true ) . '; ' . 'RESPONSE:' . print_r( $response, true ) );

		// Infusion API returns ERROR in ALL CAPS if there's an error
		if ( strstr( print_r( $response, true ), 'ERROR' ) ) {
			if ( $this->attemptCnt > 1 ) {
				$this->sendDataToLog( __FUNCTION__ . ': 2 attempts resulted in no response from infObj->findByEmail(' . $email . ')' );

				return false;
			}

			// Sleep for 5 minutes and try one more time -- InfusionSoft API can get backed up
			sleep( 300 );
			$response = $this->searchInfContact( $postArr );
			$this->attemptCnt ++;
		}

		$this->sendDataToLog( __FUNCTION__ . ': email: ' . $email . ';' . print_r( $response, true ) );

		return $response;
	}

	public function updateInfContact( $infId, $updateArr ) {
		/**
		 *  Takes POST'd data and updates fields in $updateArr
		 *
		 */
		if ( ! $infId ) {
			return false;
		}
		$conID = $this->infObj->updateCon( $infId, $updateArr );

		$this->sendDataToLog( __FUNCTION__ . ': ' . print_r( $conID, true ) );

		return $conID;
	}


}

