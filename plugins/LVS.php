<?php
class LVS extends Plugins
{
	const URL = 'http://lfsforum.net/forumdisplay.php?f=312';
	const NAME = 'LVS';
	const AUTHOR = 'PRISM Dev Team';
	const VERSION = PHPInSimMod::VERSION;
	const DESCRIPTION = 'Lap Verification System.';

	private $LVS = TRUE;
	private $Path = array();
	private $Track = '';

	public function __construct()
	{
		$this->registerSayCommand('prism lvs', 'cmdLVSToggle', '<On|Off> - Turns Lap Verification System On / Off', ADMIN_CVAR + ADMIN_TRACK);
		$this->registerPacket('onTrack', ISP_STA, ISP_RST);
		$this->registerPacket('onMCI', ISP_MCI);
	}

	public function cmdLVSToggle($cmd, $ucid)
	{
		$MTC = new IS_MTC;
		$MTC->UCID($ucid);
		if (($argc = count($argv = str_getcsv($cmd, ' '))) > 2)
		{
			$OnOff = strtolower($argv[2]);
			if ($OnOff == 'on')
			{
				$this->LVS = TRUE;
				$MTC->Text('Lap Verification System is now ^3On^8!');
			}
			else if ($OnOff == 'off')
			{
				$this->LVS = FALSE;
				$MTC->Text('Lap Verification System is now ^3Off^8!');
			}
			else
				$MTC->Text('Please provide a ^3On^8 or ^3Off^8 only as an argument to this command.');
		}
		else
			$MTC->Text('Lap Verification System is currently ^3' . (($this->LVS) ? 'On' : 'Off') . '^8.');
		$MTC->Send();
	}
	
	public function onRST(Struct $Packet)
	{
		if ($this->Track == $Packet->Track)
			return;
		else
			$this->Track = $Packet->Track;

		$file = "C:/LFS/data/pth/${this->Track}.pth";
		if (!file_exists($file))
			return;

		$pth = new pth($file);
		$this->Path = array();
		foreach ($pth->Nodes as $Node)
		{
			$this->Path[] = (array) $Node->Center;
		}
	}
	
	public function onMCI(IS_MCI $MCI)
	{
		if ($this->LVS === FALSE)
			return;

		if (!$this->isInPoly($MCI->X, $MCI->Y, $this->Path)
	}
}
?>