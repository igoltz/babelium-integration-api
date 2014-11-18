<?php


if(!defined('CLI_SERVICE_PATH'))
	define('CLI_SERVICE_PATH', '/var/www/babelium/services');

require_once CLI_SERVICE_PATH . '/utils/Datasource.php';
require_once CLI_SERVICE_PATH . '/utils/Config.php';
require_once CLI_SERVICE_PATH . '/utils/VideoProcessor.php';

//Zend Framework should be on php.ini's include_path
require_once 'Zend/Loader.php';

class VideoCollage{

	private $filePath;
	private $red5Path;

	private $evaluationFolder;
	private $exerciseFolder;
	private $responseFolder;

	private $conn;
	private $mediaHelper;

	public function VideoCollage(){
		$settings = new Config();
		$this->filePath = $settings->filePath;
		$this->imagePath = $settings->imagePath;
		$this->posterPath = $settings->posterPath;
		$this->red5Path = $settings->red5Path;

		$this->conn = new Datasource($settings->host, $settings->db_name, $settings->db_username, $settings->db_password);
		$this->mediaHelper = new VideoProcessor();

		$this->_getResourceDirectories();
	}

	private function _getResourceDirectories(){
		$sql = "SELECT prefValue
				FROM preferences
				WHERE (prefName='exerciseFolder' OR prefName='responseFolder' OR prefName='evaluationFolder') 
				ORDER BY prefName";
		$result = $this->conn->_multipleSelect($sql);
		if($result){
			$this->evaluationFolder = $result[0] ? $result[0]->prefValue : '';
			$this->exerciseFolder = $result[1] ? $result[1]->prefValue : '';
			$this->responseFolder = $result[2] ? $result[2]->prefValue : '';
		}
	}
	
	public function makeResponseCollages(){
		$responsesToMerge = false;
		//Only retrieve the responses made by the users that use the Moodle Babelium plugin
		$sql = "SELECT DISTINCT(r.file_identifier) as responseName 
				FROM response r INNER JOIN user u ON r.fk_user_id=u.ID 
                INNER JOIN moodle_api um ON u.id=um.fk_user_id";
		
		$result = $this->conn->_multipleSelect($sql);
		if($result){
			foreach($result as $r){
					$result = $this->mergeResponseWithExercise($r->responseName);
					if($result)
						$responsesToMerge = true;
			}
			if(!$responsesToMerge)
				echo "\tThere were no responses to merge\n";
		} else {
			echo "\tThere were no responses to merge\n";	
		}
	}

	public function mergeResponseWithExercise($responseName=null){

		if(!$responseName)
			return false;

		$sql = "SELECT e.name, r.file_identifier, r.character_name as chosen_role, sl.show_time, sl.hide_time, er.character_name
			    FROM exercise e 
     			INNER JOIN response r ON e.id=r.fk_exercise_id
     		    INNER JOIN subtitle s ON r.fk_subtitle_id=s.id
     			INNER JOIN subtitle_line sl ON sl.fk_subtitle_id=s.id
     			INNER JOIN exercise_role er ON er.id=sl.fk_exercise_role_id
			    WHERE (e.status='Available' AND s.complete=1 AND r.file_identifier='%s')";

		$results = $this->conn->_multipleSelect($sql, $responseName);
		if($results){
			$results = $this->sort_list_by_field($results,'show_time',SORT_NUMERIC);
			$exerciseName = $results[0]->name;
			$exercisePath = $this->red5Path.'/'.$this->exerciseFolder.'/'.$exerciseName.'.flv';
			$responsePath = $this->red5Path.'/'.$this->responseFolder.'/'.$responseName.'.flv';
			$tmpFolder = $this->filePath.'/'.$responseName;	
			try {
				if(file_exists($this->red5Path.'/'.$this->responseFolder.'/'.$responseName.'_merge.flv')){
					//echo "Response ".$responseName.".flv is already merged\n";
					return false;
				}
				
				echo "\nMerging ".$responseName.".flv with it's exercise\n";
				
				//Make a folder to store the temporary files
				$this->makeTempFolder($tmpFolder);

				//Extract exercise audio
				$r = $this->mediaHelper->demuxEncodeAudio($exercisePath, $tmpFolder.'/'.$exerciseName.'.wav');

				//Extract response audio
				$r = $this->mediaHelper->demuxEncodeAudio($responsePath, $tmpFolder.'/'.$responseName.'.wav');

				$split_times = array();

				//First split
				$t = new stdClass();
				$t->start = 0;
				$t->end = $results[0]->show_time;
				$t->volume = -1;
				if(($t->end - $t->start) > 0)
					$split_times[] = $t;
					
				$chosenRole = $results[0]->chosen_role;

				for($i=0;$i<count($results);$i++){
					//Gaps with subtitles
					$t = new stdClass();
					$t->start = $results[$i]->show_time;
					$t->end = $results[$i]->hide_time;
					$t->volume = ($chosenRole == $results[$i]->character_name) ? 0 : -1;
					if(($t->end - $t->start) > 0)
						$split_times[] = $t;

					//Gaps without subtitles
					if($i<(count($results)-1)){
						$t = new stdClass();
						$t->start = $results[$i]->hide_time;
						$t->end = $results[$i+1]->show_time;
						$t->volume = -1;
						if(($t->end - $t->start) > 0)
							$split_times[] = $t;
					}
				}

				//Last split
				$t = new stdClass();
				$t->start = $results[count($results)-1]->hide_time;
				$t->end =  -1;
				$t->volume = -1;
				//if(($t->end - $t->start) > 0)
				$split_times[] = $t;

				//Make audio subsamples following the subtitle times
				for($i=0;$i<count($split_times);$i++){
					$outputPath = sprintf("%s/%s_%02d.wav",$tmpFolder,$exerciseName,$i);
					if($split_times[$i]->volume == 0)
						$r = $this->mediaHelper->audioSubsample( $tmpFolder.'/'.$responseName.'.wav', $outputPath, $split_times[$i]->start, $split_times[$i]->end, -1); //Use 800 in last parameter to boost audio volume
					else
						$r = $this->mediaHelper->audioSubsample( $tmpFolder.'/'.$exerciseName.'.wav', $outputPath, $split_times[$i]->start, $split_times[$i]->end, -1);
					//print_r($r."\n");
				}
					
				//Concat the modified audio pieces to get the original audio length
				$r = $this->mediaHelper->concatAudio($tmpFolder,$exerciseName,$tmpFolder);
				//print_r($r."\n");
				
				
				//Check if the response has a video stream or not
				$responseInfo = $this->mediaHelper->retrieveMediaInfo($responsePath);
				if(isset($responseInfo->hasVideo) && $responseInfo->hasVideo){
					//Pad the exercise video and add the response video as an overlay. Also replace the original audio with the audio collage
					$r = $this->mediaHelper->mergeVideo($exercisePath, $responsePath, $this->red5Path.'/'.$this->responseFolder.'/'.$responseName.'_merge.flv', $tmpFolder.'/'.$exerciseName.'collage.wav');
				} else {
					$r = $this->mediaHelper->muxEncodeAudio($exercisePath, $this->red5Path.'/'.$this->responseFolder.'/'.$responseName.'_merge.flv', $tmpFolder.'/'.$exerciseName.'collage.wav');
				}
				//print_r($r."\n");

				//Delete the temporary resources
				$this->removeTempFolder($tmpFolder);
				return true;
			} catch (Exception $e){
				//The workflow failed at some point. Remove the files created up until that point.
				$this->removeTempFolder($tmpFolder);
				//$this->linkPlaceHolder($responseName);
				echo $e->getMessage();
				return false;
			}
		} else {
			echo ("Response not found or exercise not available\n");
			return false;
		}
	}
	
	private function sort_list_by_field($list, $field, $type=SORT_STRING, $order=SORT_ASC){
		if (!$list || !$field || !is_array($list)) return;
		if (!in_array($order,array(SORT_ASC,SORT_DESC)) || !in_array($type,array(SORT_REGULAR,SORT_NUMERIC,SORT_STRING,SORT_LOCALE_STRING))) return;
		$sortedList = $list;
		$sortingArray = array();
		foreach($sortedList as $listItem){
			foreach($listItem as $key=>$value){
				if(!isset($sortingArray[$key])){
					$sortingArray[$key] = array();
				}
				//Force the lowercase conversion of the values to perform a case insensitive sorting
				$sortingArray[$key][] = mb_strtolower($value,'UTF-8');
			}
		}
		array_multisort($sortingArray[$field], $order, $type, $sortedList);
		return $sortedList;
	}

	private function makeTempFolder($tmpFolder){
		if(!file_exists($tmpFolder)){
			if(!mkdir($tmpFolder))
				echo "Couldn't create the folder: ".$tmpFolder."\n";
		} else {
			echo "Folder already exists: ".$tmpFolder."\n";
		}
	}

	private function removeTempFolder($tmpFolder){
		if(is_dir($tmpFolder)){
			$folder = dir($tmpFolder);
			while (false !== ($entry = $folder->read())) {
				$entryFullPath = $tmpFolder.'/'.$entry;
				if(!is_dir($entryFullPath)){
					if(!unlink($entryFullPath))
					echo "Error while removing temp file: ".$entryFullPath."\n";
				}
			}
			$folder->close();
			if(!rmdir($tmpFolder))
				echo "Error while removing temp folder: ".$tmpFolder."\n";
		}
	}
}
