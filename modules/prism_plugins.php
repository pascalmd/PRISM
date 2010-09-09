<?php
/**
 * PHPInSimMod - Plugin Module
 * @package PRISM
 * @subpackage Plugin
*/

define('CLIENT_PRINT_CHAT', 1);

// Admin
define('ADMIN_ACCESS',				1);			# Flag "a", Allows you to issue commands from the remote console or web admin area.
define('ADMIN_BAN',					2);			# Flag "b", Allows you to ban and unban clients.
define('ADMIN_CVAR',				4);			# Flag "c", Allows you to change the configuration of LFS.
define('ADMIN_LEVEL_D',				8);			# Flag "d", 
define('ADMIN_LEVEL_E',				16);		# Flag "e", 
define('ADMIN_LEVEL_F',				32);		# Flag "f", 
define('ADMIN_GAME',				64);		# Flag "g", Allows you to change the way the game is played.
define('ADMIN_HOST',				128);		# Flag "h", Allows you to change the way the host runs.
define('ADMIN_IMMUNITY',			256);		# Flag "i", Allows you to be immune to admin commands.
define('ADMIN_LEVEL_J',				512);		# Flag "j", 
define('ADMIN_KICK',				1024);		# Flag "k", Allows you to kick clients from server.
define('ADMIN_LEVEL_L',				2048);		# Flag "l", 
define('ADMIN_TRACK',				4096);		# Flag "m", Allows you to change the track on the server.
define('ADMIN_LEVEL_N',				8192);		# Flag "n", 
define('ADMIN_LEVEL_O',				16384);		# Flag "o", 
define('ADMIN_PENALTIES',			32768);		# Flag "p", Allows you to set a penalty on any client.
define('ADMIN_RESERVATION',			65536);		# Flag "q", Allows you to join in a reserved slot.
define('ADMIN_RESERVATION',			131072);	# Flag "r", Allows you to send race control messages.
define('ADMIN_SPECTATE',			262144);	# Flag "s", Allows you to spectate and pit a client or all clients.
define('ADMIN_CHAT',				524288);	# Flag "t", Allows you to send messages to clients in their chat area.
define('ADMIN_UNIMMUNIZE',			1048576);	# Flag "u", Allows you to run commands on immune admins also.
define('ADMIN_VOTE',				2097152);	# Flag "v", Allows you to start or stop votes for anything.
define('ADMIN_LEVEL_W',				4194304);	# Flag "w", 
define('ADMIN_LEVEL_X',				8388608);	# Flag "x", 
define('ADMIN_LEVEL_Y',				16777216);	# Flag "y", 
define('ADMIN_LEVEL_Z',				33554432);	# Flag "z", 

abstract class Plugins
{
	/** These consts should _ALWAYS_ be defined in your classes. */
	/* const NAME;			*/
	/* const DESCRIPTION;	*/
	/* const AUTHOR;		*/
	/* const VERSION;		*/

	/** Properties */
	// Timers
	public $crons = array();
	public $timers = array();
	public $callbacks = array();
	// Callbacks
	private $callbackPackets = array(); # registerPacket
	public $insimCommands = array();
	public $localCommands = array();
	public $sayCommands = array();

	/** Construct */
	public function __construct(&$parent)
	{
		$this->parent =& $parent;
	}
	protected function sendPacket($packetClass)
	{
		return $this->parent->sendPacket($packetClass);
	}

	/** Parse Methods */
	public function readFlags($flagsString = '')
	{
		# We don't have anything to parse.
		if ($flagsString == '')
			return FALSE;

		$flagsBitwise = 0;
		for ($chrPointer = 0, $strLen = strlen($flagsString); $chrPointer < $strLen; ++$chrPointer)
		{
			# Convert this charater to it's ASCII int value.
			$char = ord($flagsString{$chrPointer});

			# We only want a (ASCII = 97) through z (ASCII 122), nothing else.
			if ($char < 97 || $char > 122)
				continue;

			# Check we have already set that flag, if so skip it!
			if ($flagsBitwise & (1 << ($char - 97)))
				continue;

			# Add the value to our $flagBitwise intager.
			$flagsBitwise += (1 << ($char - 97));
		}
		return $flagsBitwise;
	}

	/** Handle Methods */
	// For people who perfered predefined functions (AMX Mod X style - Will be in 0.5.0)
	public function handlePacket(Struct $packet)
	{
		// In the event that people will want predefined functions for connection and disconnection
		// this function will allow that to happen. So one could define public function clientConnect()
		// and without having to make a registerPacket function call, this call will know that you want to know
		// about ISP_NCN packets, and it will forward the useful information into the clientConnect packet.
		
		// This allows for the abstraction level between the people who want to do packet level work, and those who
		// just want to get their job done, and let us (the PRISM devs) handle all of the nitty gritty of the InSim protocol.
	}
	// This is the yang to the registerSayCommand & registerLocalCommand function's Yin.
	public function handleCmd(IS_MSO $packet)
	{
		if ($packet->UserType == MSO_PREFIX)
			$CMD = substr($packet->Msg, $packet->TextStart + 1);
		else if ($packet->UserType == MSO_O)
			$CMD = $packet->Msg;
		else
			return;
		
		if ($packet->UserType & MSO_PREFIX AND isset($this->sayCommands[$CMD]))
		{
			$method = $this->sayCommands[$CMD]['method'];
			$this->$method($CMD, $packet->PLID, $packet->UCID, $packet);
		}
		else if ($packet->UserType == MSO_O AND isset($this->localCommands[$CMD]))
		{
			$method = $this->localCommands[$CMD]['method'];
			$this->$method($CMD, $packet->PLID, $packet->UCID, $packet);
		}
	}
	// This is the yang to the registerInsimCommand function's Yin.
	public function handleInsimCmd(IS_III $packet)
	{
		if (isset($this->insimCommands[$packet->Msg]))
		{
			$method = $this->insimCommands[$packet->Msg]['method'];
			$this->$method($packet->Msg, $packet->PLID, $packet->UCID, $packet);
		}
	}

	/** Register Methods */
	// Directly registers a packet to be handled by a callbackMethod within the plugin.
	protected function registerPacket($callbackMethod, $PacketType)
	{
		$this->callbacks[$PacketType][] = $callbackMethod;
		$args = func_get_args();
		for ($i = 2, $j = count($args); $i < $j; ++$i)
			$this->callbacks[$args[$i]][] = $callbackMethod;
	}

	// Setup the callbackMethod trigger to accapt a command that could come from anywhere.
	protected function registerCommand($cmd, $callbackMethod, $info = "", $defaultAdminLevelToAccess = -1)
	{
		$this->registerConsoleCommand($cmd, $callbackMethod, $info);
		$this->registerInsimCommand($cmd, $callbackMethod, $info, $defaultAdminLevelToAccess);
		$this->registerLocalCommand($cmd, $callbackMethod, $info, $defaultAdminLevelToAccess);
		$this->registerSayCommand($cmd, $callbackMethod, $info, $defaultAdminLevelToAccess);
	}
	// Any command that comes from the PRISM console. (STDIN)
	protected function registerConsoleCommand($cmd, $callbackMethod, $info = "") {}
	// Any command that comes from the "/i" type. (III)
	protected function registerInsimCommand($cmd, $callbackMethod, $info = "", $defaultAdminLevelToAccess = -1)
	{
		if (!isset($this->callbacks[ISP_III]) && !isset($this->callbacks[ISP_III]['handleInsimCmd']))
		{	# We don't have any local callback hooking to the ISP_MSO packet, make one.
			$this->registerPacket('handleInsimCmd', ISP_III);
		}
		$this->insimCommands[$cmd] = array('method' => $callbackMethod, 'info' => $info, 'access' => $defaultAdminLevelToAccess);
	}
	// Any command that comes from the "/o" type. (MSO->Flags = MSO_O)
	protected function registerLocalCommand($cmd, $callbackMethod, $info = "", $defaultAdminLevelToAccess = -1)
	{
		if (!isset($this->callbacks[ISP_MSO]) && !isset($this->callbacks[ISP_MSO]['handleCmd']))
		{	# We don't have any local callback hooking to the ISP_MSO packet, make one.
			$this->registerPacket('handleCmd', ISP_MSO);
		}
		$this->localCommands[$cmd] = array('method' => $callbackMethod, 'info' => $info, 'access' => $defaultAdminLevelToAccess);
	}
	// Any say event with prefix charater (ISI->Prefix) with this command type. (MSO->Flags = MSO_PREFIX)
	protected function registerSayCommand($cmd, $callbackMethod, $info = "", $defaultAdminLevelToAccess = -1)
	{
		if (!isset($this->callbacks[ISP_MSO]) && !isset($this->callbacks[ISP_MSO]['handleCmd']))
		{	# We don't have any local callback hooking to the ISP_MSO packet, make one.
			$this->registerPacket('handleCmd', ISP_MSO);
		}
		$this->sayCommands[$cmd] = array('method' => $callbackMethod, 'info' => $info, 'access' => $defaultAdminLevelToAccess);
	}

	// Setups a timer to run at a certain interval.
	protected function registerTimerInterval($interval, $callbackMethod)
	{
		
	}
	// Schedules a method call to run periodically at certain times or dates.
	protected function registerTimerCron($cronExpression, $callbackMethod)
	{	# The name cron comes from the word "chronos", Greek for "time".
		
	}

	// Sets up a Console Varable (CVAR) to be utlizied by this plugin.
	public function registerCvar($cvar, $defaultValue, $defaultAdminLevelToChange) {}

	/** Server Methods */
	public function serverPrint($Msg) {}
	public function serverSay($Msg) {}
	public function serverGetTrack() {}
	public function serverGetName()
	{
		return $this->parent->curHostID;
	}
	public function serverGetSectors() {}
	public function serverGetClients() {}
	public function serverGetPlayers() {}
	public function serverGetPacket() {}

	/** Client Methods */
	public function clientCanAccessCmd($CLID, $cmd) {}
	public function clientPrint($CLID, $Msg, $Where = CLIENT_PRINT_CHAT) {}
	public function clientIsSpectator($CLID)
	{
		# Returns true when the client is connected.
		# AND all PLIDs spawned by this client are AIs.
	}

	/** Player Methods */
	public function playerIsAI($PLID) {}
	public function playerPrint($PLID, $Msg, $Where = CLIENT_PRINT_CHAT) {}

}

?>