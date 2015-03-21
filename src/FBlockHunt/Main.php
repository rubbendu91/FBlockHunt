<?php
namespace FBlockHunt;

use pocketmine\block\Block;
use pocketmine\command\Command;
use pocketmine\command\CommandExecutor;
use pocketmine\command\CommandSender;
use pocketmine\entity\FallingSand;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\entity\EntityMotionEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerMoveEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\network\protocol\AddEntityPacket;
use pocketmine\network\protocol\AddPlayerPacket;
use pocketmine\network\protocol\RemoveEntityPacket;
use pocketmine\Player;
use pocketmine\Server;
use pocketmine\plugin\PluginBase;
use pocketmine\network\protocol\UpdateBlockPacket;
use pocketmine\network\protocol\RemoveBlockPacket;
use pocketmine\network\protocol\UseItemPacket;
use pocketmine\scheduler\CallbackTask;
use pocketmine\level\Location;
use pocketmine\tile\Sign;
use pocketmine\tile\Tile;
use pocketmine\utils\Config;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\math\Vector3;

class Main extends PluginBase implements CommandExecutor, Listener {
	/********************
	gameStatus:
		0:人数不足等待加入
		1:人数足够开始倒计时
		2:无敌状态
		3:游戏进行
	********************/
	private static $obj = null;
	public static function getInstance()
	{
		return self::$obj;
	}
	
	public function onEnable()
	{
		if(!self::$obj instanceof Main)
		{
			self::$obj = $this;
		}
		@mkdir($this->getDataFolder() ,0777 ,true);
		$this->config=new Config($this->getDataFolder() . "config.yml", Config::YAML, array());
		$this->players=array();
		if(!$this->config->exists("gameTime"))
		{
			$this->config->set("gameTime",300);
		}
		if(!$this->config->exists("waitTime"))
		{
			$this->config->set("waitTime",120);
		}
		if(!$this->config->exists("godTime"))
		{
			$this->config->set("godTime",30);
		}
		if(!$this->config->exists("hideTime"))
		{
			$this->config->set("hideTime",4);
		}
		$this->gameTime=(int)$this->config->get("gameTime");//游戏时间
		$this->waitTime=(int)$this->config->get("waitTime");//等待时间
		$this->hideTime=(int)$this->config->get("hideTime");//隐藏时间
		$this->godTime=(int)$this->config->get("godTime");//无敌时间
		//方块ID列表，纯手打欢迎指点
		$this->blockArray=array(1,2,3,4,5,14,15,16,17,20,21,24,35,45,46,47,48,56,58,61,73,82,86,98,103,129,170);
		$this->gameStatus=0;//当前状态
		$this->lastTime=0;//还没开始
		$this->players=array();//加入游戏的玩家
		$this->SetStatus=array();//设置状态
		$this->all=0;//最大玩家数量
		$this->config->save();
		$this->getServer()->getScheduler()->scheduleRepeatingTask(new CallbackTask([$this,"gameTimber"]),20);
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	public function onDataPacketSend(DataPacketSendEvent $event)
	{
		if($event->getPacket() instanceof AddPlayerPacket)
		{
			$pk=$event->getPacket();
			if($this->playerIsInGame($pk->username)===2)
			{
				$event->setCancelled(true);
			}
		}
	}
	
	public function onDataPacketReceive(DataPacketReceiveEvent $event)
	{
		$packet=$event->getPacket();
		if($packet instanceof RemoveBlockPacket || $packet instanceof UseItemPacket)
		{
			foreach($this->players as $p)
			{
				if(!$p->gameInfo->isFinder && $packet->x==$p->gameInfo->hideX && $packet->y==$p->gameInfo->hideY && $packet->z==$p->gameInfo->hideZ)
				{
					$p->sendMessage("[游戏系统] [躲猫猫] 你被打出了真实方块的状态");
					$pk=new UpdateBlockPacket();
					$pk->x=$p->gameInfo->hideX;
					$pk->y=$p->gameInfo->hideY;
					$pk->z=$p->gameInfo->hideZ;
					$pk->block=0;
					$pk->meta=0;
					$this->packetToAll($pk);
					$this->showPlayer($p,$p->gameInfo);
					$p->gameInfo->isHide=false;
					foreach($p->getLevel()->getPlayers() as $pp)
					{
						$motion = $p->getMotion();
						$pp->addEntityMovement($p->getId(), $p->getX(), $p->getY() + 0.5, $p->getZ(), $p->getYaw(), $p->getPitch());
						$pp->addEntityMotion($p->getId(), $motion->getX(), $motion->getY(), $motion->getZ());
					}
				}
			}
		}
		unset($packet,$p);
	}
	
	public function onEntityDamage(EntityDamageEvent $event)
	{
		if($event instanceof EntityDamageByEntityEvent && $event->getDamager() instanceof Player && $event->getEntity() instanceof Player)
		{
			if($this->playerIsInGame($event->getEntity())!==false && $this->playerIsInGame($event->getDamager())!==false)
			{
				if($this->gameStatus<=2)
				{
					$event->setCancelled();
				}
				else if($this->players[$event->getEntity()->getName()]->gameInfo->isFinder!=$this->players[$event->getDamager()->getName()]->gameInfo->isFinder)
				{
					if($event->getEntity()->getHealth()-$event->getDamage()<=0)
					{
						if($this->players[$event->getDamager()->getName()]->gameInfo->isFinder)
						{
							$event->setCancelled();
							$this->sendToAll("[游戏系统] [躲猫猫] 寻找者".$event->getDamager()->getName()."发现了".$event->getEntity()->getName());
							$this->players[$event->getEntity()->getName()]->gameInfo->isFinder=true;
							$event->getEntity()->setHealth($event->getEntity()->getMaxHealth());
							$this->kit($event->getEntity(),true);
							$event->getEntity()->sendMessage("[游戏系统] [躲猫猫] 你成为了一名寻找者");
						}
						else
						{
							$this->sendToAll("[游戏系统] [躲猫猫] 伪装者".$event->getDamager()->getName()."杀死了".$event->getEntity()->getName());
						}
					}
				}
				else
				{
					$event->setCancelled();
				}
			}
		}
	}
	
	public function onPlayerLevelChange(EntityLevelChangeEvent $event)
	{
		if($event->getEntity() instanceof Player && isset($this->level))
		{
			if($this->playerIsInGame($event->getEntity()->getName())!==false)
			{
				$event->setCancelled();
				$event->getEntity()->getLevel("[游戏系统] [躲猫猫] 抱歉 ,游戏中请输入 /lobby 离开");
			}
			/*else if(!$event->getEntity()->isOp() && $event->getEntity()->getLevel()==$this->level)
			{
				$event->setCancelled();
				$event->getEntity()->getLevel("[游戏系统] [躲猫猫] 你没有权限进入游戏世界");
			}*///反正大RAM用不到
		}
	}
	
	/*public function onPlayerDie(PlayerDeathEvent $event)
	{
		if($this->playerIsInGame($event->getEntity()->getName())===2)
		{
			$this->blockToPlayer($event->getEntity());
		}
	}*/
	
	public function onPlayerMove(PlayerMoveEvent $event)
	{
		if($this->gameStatus>1 && $this->playerIsInGame($event->getPlayer()->getName())===2)
		{
			foreach($event->getPlayer()->getLevel()->getPlayers() as $p)
			{
				$motion = $event->getPlayer()->getMotion();
				$p->addEntityMovement($event->getPlayer()->getId(), $event->getTo()->getX(), $event->getTo()->getY() + 0.5, $event->getTo()->getZ(), $event->getPlayer()->getYaw(), $event->getPlayer()->getPitch());
				$p->addEntityMotion($event->getPlayer()->getId(), $motion->getX(), $motion->getY(), $motion->getZ());
			}
			$to=$event->getTo();
			if($this->players[$event->getPlayer()->getName()]->gameInfo->isHide)
			{
				if((int)($this->players[$event->getPlayer()->getName()]->gameInfo->hideX-$to->x)!=0 || (int)($this->players[$event->getPlayer()->getName()]->gameInfo->hideY-$to->y)!=0 || (int)($this->players[$event->getPlayer()->getName()]->gameInfo->hideZ-$to->z)!=0)
				{
					$this->players[$event->getPlayer()->getName()]->sendMessage("[游戏系统] [躲猫猫] 你脱离了真实方块的状态");
					$pk=new UpdateBlockPacket();
					$pk->x=$this->players[$event->getPlayer()->getName()]->gameInfo->hideX;
					$pk->y=$this->players[$event->getPlayer()->getName()]->gameInfo->hideY;
					$pk->z=$this->players[$event->getPlayer()->getName()]->gameInfo->hideZ;
					$pk->block=$this->players[$event->getPlayer()->getName()]->gameInfo->oldID;
					$pk->meta=$this->players[$event->getPlayer()->getName()]->gameInfo->oldData;
					$this->packetToAll($pk);
					$this->showPlayer($this->players[$event->getPlayer()->getName()],$this->players[$event->getPlayer()->getName()]->gameInfo);
					$this->players[$event->getPlayer()->getName()]->gameInfo->isHide=false;
				}
			}
			else
			{
				if(abs($this->players[$event->getPlayer()->getName()]->gameInfo->hideX-$to->x)>=0.15 || abs($this->players[$event->getPlayer()->getName()]->gameInfo->hideY-$to->y)>=0.15 || abs($this->players[$event->getPlayer()->getName()]->gameInfo->hideZ-$to->z)>=0.15)
				{
					$this->players[$event->getPlayer()->getName()]->gameInfo->hideTime=0;
				}
			}
		}
		unset($to,$event,$val,$pk);
	}
	
	public function onPlayerMotion(EntityMotionEvent $event)
	{
		if($this->gameStatus>1 && $event->getEntity() instanceof Player && $this->playerIsInGame($event->getEntity()->getName())===2)
		{
			foreach($event->getEntity()->getLevel()->getPlayers() as $p)
			{
				$motion = $event->getEntity()->getMotion();
				$p->addEntityMotion($event->getEntity()->getId(), $motion->getX(), $motion->getY(), $motion->getZ());
			}
		}
	}
	
	public function onPlayerQuit(PlayerQuitEvent $event)
	{
		if($this->playerIsInGame($event->getPlayer())===2)
		{
			$this->blockToPlayer($event->getPlayer());
			$this->sendToAll("[游戏系统] [躲猫猫] 玩家".$event->getPlayer()->getName()."退出了游戏");
		}
		else if($this->playerIsInGame($event->getPlayer())===1)
		{
			unset($this->players[$event->getPlayer()->getName()]);
			$this->sendToAll("[游戏系统] [躲猫猫] 玩家".$event->getPlayer()->getName()."退出了游戏");
		}
		unset($event);
	}
	
	public function playerIsInGame($player)
	{
		if($player instanceof Player)
		{
			$player=$player->getName();
		}
		if(isset($this->players[$player]))
		{
			return $this->players[$player]->gameInfo->isFinder?1:2;
		}
		unset($player);
		return false;
	}
	
	public function onCommand(CommandSender $sender, Command $command, $label, array $args)
	{
		switch($args[0])
		{
		case "join":
			if($sender instanceof Player)
			{
				$this->players[$sender->getName()]=$sender;
				$this->players[$sender->getName()]->gameInfo=new gameInfo();
				$sender->sendMessage("[游戏系统] [躲猫猫] 加入成功");
				$this->sendToAll("[游戏系统] [躲猫猫] 玩家".$sender->getName()."加入了游戏");
			}
			break;
		case "start":
			$this->gameStatus=1;
			$this->lastTime=5;
			$this->sendToAll("[游戏系统] [躲猫猫] 已强制开始游戏");
			break;
		}
		return true;
	}
	
	public function playerToBlock(Player $player, $blockId)
	{
		$this->blockToPlayer($player);
		$player->despawnFromAll();
		$pk = new AddEntityPacket();
		$pk->type = FallingSand::NETWORK_ID;
		$pk->eid = $player->getId();
		$pk->x = $player->getX();
		$pk->y = $player->getY();
		$pk->z = $player->getZ();
		$pk->did = -$blockId;

		foreach($player->getLevel()->getPlayers() as $p)
		{
			$p->dataPacket($pk);
		}
		$this->getLogger()->warning("player:".$player->getName().",ID:".$blockId);
		$this->players[$player->getName()]=$player;
		$this->players[$player->getName()]->gameInfo=new gameInfo();
		$this->players[$player->getName()]->gameInfo->hideID=$blockId;
	}
	
	public function blockToPlayer(Player $player)
	{
		if($this->playerIsInGame($player->getName())!==2)
		{
			return;
		}
		$player->despawnFromAll();
		$pk=new RemoveEntityPacket();
		$pk->eid=$player->getId();
		if($this->gameStatus>1)
		{
			foreach($player->getLevel()->getPlayers() as $p)
			{
				$p->dataPacket($pk);
			}
		}
		unset($this->players[$player->getName()]);
		$player->spawnToAll();
		if($player->gameInfo->isHide)
		{
			$pk=new UpdateBlockPacket();
			$pk->x=$player->gameInfo->hideX;
			$pk->y=$player->gameInfo->hideY;
			$pk->z=$player->gameInfo->hideZ;
			$pk->block=$player->gameInfo->oldID;
			$pk->meta=$player->gameInfo->oldData;
			$this->packetToAll($pk);
			$player->gameInfo->isHide=false;
		}
	}
	
	public function packetToAll($packet)
	{
		foreach($this->players as $val)
		{
			$val->dataPacket($packet);
		}
		unset($packet,$val);
	}
	
	public function randBlockID()
	{
		return $this->blockArray[mt_rand(0,count($this->blockArray)-1)];
	}
	
	public function kit($player,$isFinder)
	{
		$inv=$player->getInventory();
		if(!$inv instanceof Inventory)
		{
			return false;
		}
		$inv->clearAll();
		if($isFinder)
		{
			$inv->setArmorItem(0,Item::get(310));
			$inv->setArmorItem(1,Item::get(311));
			$inv->setArmorItem(2,Item::get(312));
			$inv->setArmorItem(3,Item::get(313));
			$inv->setItem(0,Item::get(276));
		}
		else
		{
			$inv->setArmorItem(0,Item::get(306));
			$inv->setArmorItem(1,Item::get(307));
			$inv->setArmorItem(2,Item::get(308));
			$inv->setArmorItem(3,Item::get(309));
			$inv->setItem(0,Item::get(267));
		}
		return true;
	}
	
	public function hidePlayer(Player $player)
	{
		$player->despawnFromAll();
		$pk = new RemoveEntityPacket();
		$pk->eid = $player->getId();
		foreach($player->getLevel()->getPlayers() as $p)
		{
			$p->dataPacket($pk);
		}
	}
	
	public function showPlayer(Player $player,gameInfo $info)
	{
		$this->hidePlayer($player);
		$pk = new AddEntityPacket();
		$pk->type = FallingSand::NETWORK_ID;
		$pk->eid = $player->getId();
		$pk->x = $player->getX();
		$pk->y = $player->getY();
		$pk->z = $player->getZ();
		$pk->did = -$info->hideID;
		foreach($player->getLevel()->getPlayers() as $p)
		{
			$p->dataPacket($pk);
		}
	}
	
	public function gameTimber()
	{
		$this->changeStatusSign();
		if($this->gameStatus==0)
		{
			if(count($this->players)>1)
			{
				$this->sendToAll("[游戏系统] [躲猫猫] 人数达到底限 ,开始倒计时");
				$this->gameStatus=1;
				$this->lastTime=$this->waitTime;
			}
		}
		if($this->gameStatus==1)
		{
			if(count($this->players)<=1)
			{
				$this->sendToAll("[游戏系统] [躲猫猫] 人数不足 ,倒计时已停止");
				$this->gameStatus=0;
			}
			else
			{
				$this->lastTime--;
				switch($this->lastTime)
				{
				case 1:
				case 2:
				case 3:
				case 4:
				case 5:
				case 10:
				//case 20:
				case 30:
					$this->sendToAll("[游戏系统] [躲猫猫] 游戏还有".$this->lastTime."秒开始");
					break;
				case 60:
					$this->sendToAll("[游戏系统] [躲猫猫] 游戏还有1分钟开始");
					break;
				case 90:
					$this->sendToAll("[游戏系统] [躲猫猫] 游戏还有1分30秒开始");
					break;
				case 120:
					$this->sendToAll("[游戏系统] [躲猫猫] 游戏还有2分钟开始");
					break;
				case 150:
					$this->sendToAll("[游戏系统] [躲猫猫] 游戏还有2分30秒开始");
					break;
				case 0:
					$this->gameStatus=2;
					$this->sendToAll("[游戏系统] [躲猫猫] 游戏开始");
					$this->lastTime=$this->godTime;
					$this->all=count($this->players);
					$this->RandomFinder();
					foreach($this->players as $val)
					{
						$val->setMaxHealth(20);
						$val->setHealth(20);
						$val->setLevel($this->level);
						if(!$val->gameInfo->isFinder)
						{
							$this->playerToBlock($val,$this->randBlockID());
						}
					}
					break;
				}
			}
		}
		if($this->gameStatus==2)
		{
			$this->lastTime--;
			if($this->lastTime<=0)
			{
				$this->gameStatus=3;
				$this->sendToAll("[游戏系统] [躲猫猫] 无敌状态解除");
				$this->lastTime=$this->gameTime;
			}
		}
		if($this->gameStatus==3)
		{
			$this->checkAllDie();
			$this->lastTime--;
			switch($this->lastTime)
			{
			case 1:
			case 2:
			case 3:
			case 4:
			case 5:
			case 10:
			//case 20:
			case 30:
				$this->sendToAll("[游戏系统] [躲猫猫] 游戏还有".$this->lastTime."秒结束");
				break;
			case 60:
				$this->sendToAll("[游戏系统] [躲猫猫] 游戏还有1分钟结束");
				break;
			case 90:
				$this->sendToAll("[游戏系统] [躲猫猫] 游戏还有1分30秒结束");
				break;
			case 120:
				$this->sendToAll("[游戏系统] [躲猫猫] 游戏还有2分钟结束");
				break;
			case 150:
				$this->sendToAll("[游戏系统] [躲猫猫] 游戏还有2分30秒结束");
				break;
			case 0:
				$this->sendToAll("[游戏系统] [躲猫猫] 时间到 ,躲藏者胜利 !");
				$this->gameStatus=0;
				$this->lastTime=2334;
				$this->teleportAllBack();
				$this->clearBlocks();
				$this->players=array();
				break;
			}
		}
		$this->changeStatusSign();
		if($this->gameStatus>1)
		{
			foreach($this->players as $val)
			{
				if($val->gameInfo->isFinder)
				{
					continue;
				}
				$val->gameInfo->hideTime++;
				if($val->gameInfo->hideTime==$this->hideTime)
				{
					if($val->getLevel()->getBlock(new Vector3($this->fixPos($val->getX()),$this->fixPos($val->getY()),$this->fixPos($val->getZ())))->getID()!=0)
					{
						$val->sendMessage("[游戏系统] [躲猫猫] 你不能在这里进入真实方块状态");
						$val->gameInfo->hideTime=0;
					}
					else
					{
						$val->sendMessage("[游戏系统] [躲猫猫] 你变成了一个真正的方块");
						$pk=new UpdateBlockPacket();
						$val->gameInfo->hideX=$this->fixPos($val->getX());
						$val->gameInfo->hideY=$this->fixPos($val->getY());
						$val->gameInfo->hideZ=$this->fixPos($val->getZ());
						$val->gameInfo->isHide=true;
						$pk->x=$this->fixPos($val->getX());
						$pk->y=$this->fixPos($val->getY());
						$pk->z=$this->fixPos($val->getZ());
						$pk->block=$val->gameInfo->hideID;
						$pk->meta=$val->gameInfo->hideData;
						$this->packetToAll($pk);
						$this->hidePlayer($val);
					}
				}
			}
		}
	}
	
	public function checkAllDie()
	{
		$havelife=false;
		foreach($this->players as $p)
		{
			if($p->gameInfo->isFinder)
			{
				$havelife=true;
				break;
			}
		}
		if(!$havelife)
		{
			$this->sendToAll("[游戏系统] [躲猫猫] 寻找者全部死亡 ,躲藏者胜利 !");
			$this->gameStatus=0;
			$this->lastTime=2334;
			$this->teleportAllBack();
			$this->clearBlocks();
			$this->players=array();
			return;
		}
		$havelife=false;
		foreach($this->players as $p)
		{
			if(!$p->gameInfo->isFinder)
			{
				$havelife=true;
				break;
			}
		}
		if(!$havelife)
		{
			$this->sendToAll("[游戏系统] [躲猫猫] 隐藏着全部死亡或被找到 ,寻找者胜利 !");
			$this->gameStatus=0;
			$this->lastTime=2334;
			$this->teleportAllBack();
			$this->clearBlocks();
			$this->players=array();
			return;
		}
	}
	
	public function clearBlocks()
	{
		foreach($this->players as $p)
		{
			$this->blockToPlayer($p);
		}
		unset($p);
	}
	
	public function teleportAllBack()
	{
		//TODO
	}
	
	public function RandomFinder()
	{
		$cou=(int)($this->all/2);
		for($i=0;$i<$cou;$i++)
		{
			$be=array_keys($this->players)[mt_rand(0,$this->all-1)];
			while($this->players[$be]->gameInfo->isFinder)
			{
				$be=array_keys($this->players)[mt_rand(0,$this->all-1)];
			}
			$this->players[$be]->gameInfo->isFinder=true;
			$this->players[$be]->sendMessage("[游戏系统] [躲猫猫] 你成为了寻找者");
		}
		foreach($this->players as $p)
		{
			$this->kit($p,$p->gameInfo->isFinder);
		}
		unset($p,$cou,$be);
	}
	
	public function fixPos($p)
	{
		if($p<0)
		{
			return (int)($p-1);
		}
		return (int)($p);
	}
	
	public function changeStatusSign()
	{
		$this->level=Server::getInstance()->getLevelByName("RAM");
		//$this->getLogger()->info("status:{$this->gameStatus},lastTime:{$this->lastTime}");
	}
	
	public function sendToAll($msg)
	{
		foreach($this->players as $val)
		{
			$val->sendMessage($msg);
		}
		$this->getServer()->getLogger()->info($msg);
		unset($val,$msg);
	}
}

class gameInfo
{
	public $hideTime=0;
	public $hideID=0,$hideData=0,$oldID=0,$oldData=0;
	public $hideX=0,$hideY=0,$hideZ=0;
	public $isHide=false;
	public $isFinder=false;
}
?>
