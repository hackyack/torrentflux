<?php

/* $Id$ */

/*******************************************************************************

 LICENSE

 This program is free software; you can redistribute it and/or
 modify it under the terms of the GNU General Public License (GPL)
 as published by the Free Software Foundation; either version 2
 of the License, or (at your option) any later version.

 This program is distributed in the hope that it will be useful,
 but WITHOUT ANY WARRANTY; without even the implied warranty of
 MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 GNU General Public License for more details.

 To read the license please visit http://www.gnu.org/copyleft/gpl.html

*******************************************************************************/

// VuzeRPC
require_once("inc/classes/VuzeRPC.php");

require_once("inc/functions/functions.rpc.vuze.php");

/**
 * class ClientHandler for vuze xmwebui rpc
 */
class ClientHandlerVuzeRPC extends ClientHandler
{

	// =========================================================================
	// ctor
	// =========================================================================

	/**
	 * ctor
	 */
	function ClientHandlerVuzeRPC() {
		global $cfg;

		$this->type = "torrent";
		$this->client = "vuzerpc";
		$this->binSystem = "java";
		$this->binSocket = "java";
		$this->binClient = "java";

		$this->useRPC = true;
		//$this->binClient = $cfg["docroot"]."bin/clients/vuzerpc/vuzerpc_cmd.php";
	}

	// =========================================================================
	// public methods
	// =========================================================================

	/**
	 * starts a transfer
	 *
	 * @param $transfer name of the transfer
	 * @param $interactive (boolean) : is this a interactive startup with dialog ?
	 * @param $enqueue (boolean) : enqueue ?
	 */
	function start($transfer, $interactive = false, $enqueue = false) {
		global $cfg;

		// set vars
		$this->_setVarsForTransfer($transfer);

		// log
		$this->logMessage($this->client."-start : ".$transfer."\n", true);

		$this->vuze = VuzeRPC::getInstance();
		$vuze = & $this->vuze;

		// do special-pre-start-checks
		if (!VuzeRPC::isRunning()) {
			$msg = "VuzeRPC not reacheable, cannot start transfer ".$transfer;
			AuditAction($cfg["constants"]["error"], $msg);
			$this->logMessage($msg."\n", true);

			// write error to stat
			$sf = new StatFile($this->transfer, $this->owner);
			$sf->time_left = 'Error: VuzeRPC down';
			$sf->write();

			// return
			return false;
		}

		// init starting of client
		$this->_init($interactive, $enqueue, true, false);

		// only continue if init succeeded (skip start / error)
		if ($this->state != CLIENTHANDLER_STATE_READY) {
			if ($this->state == CLIENTHANDLER_STATE_ERROR) {
				$msg = "Error after init (".$transfer.",".$interactive.",".$enqueue.",true,".$cfg['enable_sharekill'].")";
				array_push($this->messages , $msg);
				$this->logMessage($msg."\n", true);
			}
			// return
			return false;
		}
		
		$this->command = "echo ok";

		// build the command-string
		$content  = $cfg['user']."\n";
		$content .= $this->savepath."\n";
		$content .= $this->rate."\n";
		$content .= $this->drate."\n";
		$content .= $this->maxuploads."\n";
		$content .= $this->superseeder."\n";
		$content .= $this->runtime."\n";
		$content .= $this->sharekill_param."\n";
		$content .= $this->minport."\n";
		$content .= $this->maxport."\n";
		$content .= $this->maxcons."\n";
		$content .= $this->rerequest;
/*
		$this->command  = "echo -e ".tfb_shellencode($content)." > ".tfb_shellencode($cfg["path"].'.vuzerpc/run/'.$transfer);

		//$this->command .= " && ";
		//$this->command .= "echo r > ".tfb_shellencode($cfg["path"].'.vuzerpc/vuzerpc.cmd');

		//if ($this->isWinOS()) {
		//	file_put_contents($cfg["path"].'.vuzerpc/run/'.$transfer, $content);
		//	$this->command = "echo r > ".tfb_shellencode($cfg["path"].'.vuzerpc/vuzerpc.cmd');
		//}

		if (!is_dir($cfg["path"].'.vuzerpc'))
			mkdir($cfg["path"].'.vuzerpc',0775);

		if (!is_dir($cfg["path"].'.vuzerpc/run'))
			mkdir($cfg["path"].'.vuzerpc/run',0775);
*/
		// no client needed
		$this->state = CLIENTHANDLER_STATE_READY;

		// ClientHandler _start()
		$this->_start();

		$req = $vuze->torrent_add_tf($transfer,$content);
		//file_put_contents($cfg["path"].'.vuzerpc/'.$transfer.".log",serialize($req));
/*
		if (is_int($req)) {
			$id = $req;
			$tfs = $vuze->torrent_get_tf(array($id));
			$tf = array_pop($tfs);

			$sf = new StatFile($transfer);
			$sf->running = $tf['running'];
			$sf->percent_done=$tf['percentDone'];
			$sf->peers = $tf['cons'];
			$sf->time_left = $tf['eta'];
			$sf->down_speed = $tf['speedDown'];
			$sf->up_speed = $tf['speedUp'];

			$sf->write();
		}
*/
		$this->updateStatFiles($transfer);

	}

	/**
	 * stops a transfer
	 *
	 * @param $transfer name of the transfer
	 * @param $kill kill-param (optional)
	 * @param $transferPid transfer Pid (optional)
	 */
	function stop($transfer, $kill = false, $transferPid = 0) {
		global $cfg;

		// set vars
		$this->_setVarsForTransfer($transfer);

		addGrowlMessage($this->client."-stop","$transfer");

		if (!isset($this->vuze))
			$this->vuze = new VuzeRPC($cfg);

		$vuze = & $this->vuze;

		// only if vuze running and transfer exists in fluazu
		if (!VuzeRPC::isRunning()) {
			array_push($this->messages , "VuzeRPC not running, cannot stop transfer ".$transfer);
			return false;
		}

		$hash = getTransferHash($transfer);
		if (!VuzeRPC::transferExists($hash)) {
			$msg = "transfer ".$transfer." does not exist in vuze.";
			$this->logMessage($msg."\n", true);
		}
		else
		{
			// log
			$this->logMessage($this->client."-stop : ".$transfer."\n", true);

			if (!$vuze->torrent_stop_tf($hash)) {
				$msg = "transfer ".$transfer." does not exist in vuze (2).";
				$this->logMessage($msg."\n", true);
				AuditAction($cfg["constants"]["debug"], $this->client."-stop : error $hash $transfer.");
			}
		}

		// set .stat stopped
		$this->cleanStoppedStatFile($transfer);
		
		// delete .pid
		$this->_stop($kill, $transferPid);

		$this->updateStatFiles($transfer);
	}

	/**
	 * deletes a transfer
	 *
	 * @param $transfer name of the transfer
	 * @return boolean of success
	 */
	function delete($transfer) {
		global $cfg;

		// set vars
		$this->_setVarsForTransfer($transfer);
		
		$hash = getTransferHash($transfer);

		// only if transfer exists in vuze
		if (VuzeRPC::transferExists($hash)) {
			// only if vuze running
			if (!VuzeRPC::isRunning()) {
				array_push($this->messages , "vuze not running, cannot delete transfer ".$transfer);
				$this->logMessage(implode("\n",$this->messages)."\n", true);
				return false;
			}
			else
			// remove from vuze
			if (!VuzeRPC::delTransfer($hash)) {
				array_push($this->messages , $this->client.": error when deleting transfer ".$transfer." :");
				$this->messages = array_merge($this->messages, VuzeRPC::getMessages());
				$this->logMessage(implode("\n",$this->messages)."\n", true);
				return false;
			}
		} else {
			$msg = "transfer ".$transfer." does not exist in vuze, deleting pid file (delete).";
			$this->logMessage($msg."\n", true);
			@ unlink($this->transferFilePath.".pid");
		}

		// delete
		return $this->_delete();
	}

	/**
	 * gets current transfer-vals of a transfer
	 *
	 * @param $transfer
	 * @return array with downtotal and uptotal
	 */
	function getTransferCurrent($transfer) {
		global $db, $transfers;
		$retVal = array();
		// transfer from stat-file
		$sf = new StatFile($transfer);
		$retVal["uptotal"] = $sf->uptotal;
		$retVal["downtotal"] = $sf->downtotal;
		// transfer from db
		$torrentId = getTransferHash($transfer);
		$sql = "SELECT uptotal,downtotal FROM tf_transfer_totals WHERE tid = ".$db->qstr($torrentId);
		$result = $db->Execute($sql);
		$row = $result->FetchRow();
		if (!empty($row)) {
			$retVal["uptotal"] -= $row["uptotal"];
			$retVal["downtotal"] -= $row["downtotal"];
		}
		return $retVal;
	}

	/**
	 * gets current transfer-vals of a transfer. optimized version
	 *
	 * @param $transfer
	 * @param $tid of the transfer
	 * @param $sfu stat-file-uptotal of the transfer
	 * @param $sfd stat-file-downtotal of the transfer
	 * @return array with downtotal and uptotal
	 */
	function getTransferCurrentOP($transfer, $tid, $sfu, $sfd) {
		global $transfers;
		$retVal = array();
		$retVal["uptotal"] = (isset($transfers['totals'][$tid]['uptotal']))
			? $sfu - $transfers['totals'][$tid]['uptotal']
			: $sfu;
		$retVal["downtotal"] = (isset($transfers['totals'][$tid]['downtotal']))
			? $sfd - $transfers['totals'][$tid]['downtotal']
			: $sfd;
		return $retVal;
	}

	/**
	 * gets total transfer-vals of a transfer
	 *
	 * @param $transfer
	 * @return array with downtotal and uptotal
	 */
	function getTransferTotal($transfer) {
		global $transfers;
		// transfer from stat-file
		$sf = new StatFile($transfer);
		return array("uptotal" => $sf->uptotal, "downtotal" => $sf->downtotal);
	}

	/**
	 * gets total transfer-vals of a transfer. optimized version
	 *
	 * @param $transfer
	 * @param $tid of the transfer
	 * @param $sfu stat-file-uptotal of the transfer
	 * @param $sfd stat-file-downtotal of the transfer
	 * @return array with downtotal and uptotal
	 */
	function getTransferTotalOP($transfer, $tid, $sfu, $sfd) {
		return array("uptotal" => $sfu, "downtotal" => $sfd);
	}

	/**
	 * set upload rate of a transfer
	 *
	 * @param $transfer
	 * @param $uprate int
	 * @param $autosend
	 */
	function setRateUpload($transfer, $uprate, $autosend = false) {
		global $cfg;
		// set rate-field
		$this->rate = (int) $uprate;

		$result = true;
		
		$msg = "$uprate ".serialize($autosend);
		//if ($autosend) {
			$rpc = VuzeRPC::getInstance();

			$tid = getVuzeTransferRpcId($transfer);
			if ($tid > 0) {
				$byterate = 1024 * $this->rate;
				$req = $rpc->torrent_set(array($tid),'speedLimitUpload',$byterate);
				if (!isset($req->result) || $req->result != 'success') {
					$msg = $req->result;
					$result = false;
				} else {
					//Check if setting is applied
					$req = $rpc->torrent_get(array($tid),array('speedLimitUpload'));
					if (!isset($req->result) || $req->result != 'success') {
						$msg = $req->result;
						$result = false;
					} elseif (!empty($req->arguments->torrents)) {
						$torrent = array_pop($req->arguments->torrents);
						if ($torrent->speedLimitUpload != $byterate) {
							$msg = "byterate not set correctly =".$torrent->speedLimitUpload;
							//$req = $rpc->session_set('speed-limit-up', $byterate);
						}
					}
				}
			} else
				$msg = "bad tid $transfer ".$req->result;
			
			$this->logMessage("setRateUpload : ".$msg."\n", true);
			AuditAction($cfg["constants"]["debug"], $this->client."-setRateUpload : $msg.");
		//}
		return $result;
	}

	/**
	 * set download rate of a transfer
	 *
	 * @param $transfer
	 * @param $downrate int
	 * @param $autosend
	 */
	function setRateDownload($transfer, $downrate, $autosend = false) {
		global $cfg;
		// set rate-field
		$this->drate = (int) $downrate;
		
		$msg = "$downrate ".serialize($autosend);
		//if ($autosend) {
			$rpc = VuzeRPC::getInstance();

			$tid = getVuzeTransferRpcId($transfer);
			if ($tid > 0) {
				$byterate = 1024 * $this->drate;
				$req = $rpc->torrent_set(array($tid),'speedLimitDownload',$byterate);
				if (!isset($req->result) || $req->result != 'success') {
					$msg = $req->result;
					$result = false;
				} else {
					//Check if setting is applied
					$req = $rpc->torrent_get(array($tid),array('speedLimitDownload'));
					if (!isset($req->result) || $req->result != 'success') {
						$msg = $req->result;
						$result = false;
					} elseif (!empty($req->arguments->torrents)) {
						$torrent = array_pop($req->arguments->torrents);
						if ($torrent->speedLimitDownload != $byterate) {
							$msg = "byterate not set correctly =".$torrent->speedLimitDownload;
							//$req = $rpc->session_set('speed-limit-down', $byterate);
						}
					}
				}
			} else
				$msg = "bad tid $transfer ".$req->result;
			
			$this->logMessage("setRateUpload : ".$msg."\n", true);
			AuditAction($cfg["constants"]["debug"], $this->client."-setRateUpload : $msg.");
		//}
		return $result;
		
	}

	/**
	 * set runtime of a transfer
	 *
	 * @param $transfer
	 * @param $runtime bool
	 * @param $autosend
	 * @return boolean
	 */
	function setRuntime($transfer, $runtime, $autosend = false) {
		// set runtime-field
		$this->runtime = $runtime;
		
		if ($autosend) {
		//	CommandHandler::send($transfer);
		}
		return true;
	}

	/**
	 * set sharekill of a transfer
	 *
	 * @param $transfer
	 * @param $sharekill int
	 * @param $autosend
	 * @return boolean
	 */
	function setSharekill($transfer, $sharekill, $autosend = false) {
		// set sharekill
		$this->sharekill = $sharekill;
		
		// send command to client
		if ($autosend) {
			// CommandHandler::send($transfer);
		}
		return true;
	}

	/**
	 * clean stat file
	 *
	 * @param $transfer
	 * @return boolean
	 */
	function cleanStoppedStatFile($transfer) {
		$stat = new StatFile($this->transfer, $this->owner);
		//if ($stat->percent_done > 100)
		//	$stat->percent_done=100;
		return $stat->stop();
	}

	function updateStatFiles($transfer="") {
		global $cfg, $db;

		$this->vuze = VuzeRPC::getInstance();
		$vuze = & $this->vuze;

		// do special-pre-start-checks
		if (!VuzeRPC::isRunning()) {
			return;
		}

		$tfs = $vuze->torrent_get_tf();

		if (empty($tfs))
			return;

		$hashes = array("''");
		foreach ($tfs as $hash => $t) {
			$hashes[] = "'".strtolower($hash)."'";
		}

		$sql = "SELECT hash, transfer, sharekill FROM tf_transfers WHERE type='torrent' AND client IN ('vuzerpc','azureus') AND hash IN (".implode(',',$hashes).")";

		//only update one $transfer...
		if ($transfer != "")
			$sql .= " AND transfer=".$db->qstr($transfer);

		$recordset = $db->Execute($sql);
		$hashes=array();
		$sharekills=array();
		
		while (list($hash, $transfer, $sharekill) = $recordset->FetchRow()) {
			$hash = strtoupper($hash);
			$hashes[$hash] = $transfer;
			$sharekills[$hash] = $sharekill;
		}

		//convertTime
		require_once("inc/functions/functions.core.php");

		foreach ($tfs as $hash => $t) {
			if (!isset($hashes[$hash]))
				continue;

			$transfer = $hashes[$hash];

			$sf = new StatFile($transfer);
			$sf->running = $t['running'];

			if ($sf->running) {

				if ($t['eta'] > 0) {
					$sf->time_left = convertTime($t['eta']);
				}

				//(temp) force creation of pid file to fix first ones
				file_put_contents($cfg["transfer_file_path"].'/'.$transfer.".pid","rpc");

				$sf->percent_done = $t['percentDone'];

				if ($t['status'] != 9 && $t['status'] != 5) {
					$sf->peers = $t['peers'];
				}

				if ($t['seeds'] >= 0)
					$sf->seeds = $t['seeds'];

				if ($t['peers'] >= 0)
					$sf->peers = $t['peers'];

				if ((float)$t['speedDown'] > 0.0)
					$sf->down_speed = formatBytesTokBMBGBTB($t['speedDown'])."/s";
				if ((float)$t['speedUp'] > 0.0)
					$sf->up_speed = formatBytesTokBMBGBTB($t['speedUp'])."/s";

				if ($t['status'] == 8) {
					//seeding
					//$sf->percent_done = 100 + $t['sharing'];
					$sf->down_speed = "";
				}
				if ($t['status'] == 9) {
					//seeding queued
					//$sf->percent_done = 100 + $t['sharing'];
					$sf->up_speed = "";
					$sf->down_speed = "";
				}

			} else {
				$sf->down_speed = "";
				$sf->up_speed = "";
				$sf->peers = "";
				
				if ($t['eta'] < -1) {
					$sf->time_left = "Finished in ".convertTime(abs($t['eta']));
				} elseif ($sf->percent_done >= 100 && strpos($sf->time_left, 'Finished') === false) {
					$sf->time_left = "Finished!";
					$sf->percent_done = 100;
				}
				//if ($sf->percent_done < 100 && $sf->percent_done > 0)
					//$sf->percent_done = 0 - $sf->percent_done;
			}
			
			$sf->downtotal = $t['downTotal'];
			$sf->uptotal = $t['upTotal'];
			
			if (!$sf->size)
				$sf->size = $t['size'];
			
			if ($sf->seeds = -1);
				$sf->seeds = '';
			$sf->write();
		}
		
		//SHAREKILLS
		foreach ($tfs as $hash => $t) {
			if (isset($sharekills[$hash])) {
				if (($t['status']==8 || $t['status']==9) && $t['sharing'] > $sharekills[$hash]) {
					
					$transfer = $hashes[$hash];
					
					if (!$vuze->torrent_stop_tf($hash)) {
						$msg = "transfer ".$transfer." does not exist in vuze.";
						$this->logMessage($msg."\n", true);
						AuditAction($cfg["constants"]["debug"], $this->client."-stop : error $hash $transfer.");
					} else {
						// flag the transfer as stopped (in db)
						// log
						AuditAction($cfg["constants"]["stop_transfer"], $this->client."-stat. : sharekill stopped $transfer");
						stopTransferSettings($transfer);
					}
				}
			}
		}
	}

	/**
	 * gets current status of one Transfer (realtime)
	 * for transferStat popup
	 *
	 * @return array (stat) or Error String
	 */
	function monitorTransfer($transfer) {
		//by default, monitoring not available.
		$vuze = VuzeRPC::getInstance();

		// set vars
		$this->_setVarsForTransfer($transfer);
				
		//return print_r($vuze->,true);
		
		$tid = getVuzeTransferRpcId($transfer);

		if ($tid > 0) {
			$stat = $vuze->torrent_get_tf_array(array($tid));
			return $stat;
		} else {
			return $vuze->lastError;
		}
	}

	/**
	 * gets current status of one Transfer (realtime)
	 * for transferStat popup
	 *
	 * @return array (stat) or Error String
	 */
	function monitorAllTransfers() {
		//by default, monitoring not available.
		$vuze = VuzeRPC::getInstance();

		$stat = $vuze->torrent_get_tf_array();
		return $stat;
	}

}

?>
