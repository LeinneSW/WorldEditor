<?php

namespace plugin;

use pocketmine\block\Block;
use pocketmine\Player;
use pocketmine\scheduler\CallbackTask;
use pocketmine\Server;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\event\Listener;
use pocketmine\command\Command;
use pocketmine\utils\TextFormat;
use pocketmine\plugin\PluginBase;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\player\PlayerInteractEvent;

class WorldEditor extends PluginBase implements Listener{

    public $data;

    public static $pos = [];
    public static $undo = [];
    public static $redo = [];

    public static function core(){
        return Server::getInstance();
    }

    public static function yaml($file){
        return preg_replace("#^([ ]*)([a-zA-Z_]{1}[^\:]*)\:#m", "$1\"$2\":", file_get_contents($file));
    }


    public function onEnable(){
        if(file_exists(self::core()->getDataPath() . "plugins/WorldEditor/data.yml")){
            $this->data = yaml_parse(self::yaml(self::core()->getDataPath() . "plugins/WorldEditor/data.yml"));
        }else{
            $this->data = [
                "tool-id" => Item::IRON_HOE,
                "limit-block" => 80
            ];
            if(!is_dir($path = self::core()->getDataPath() . "plugins/WorldEditor/")) mkdir($path);
            file_put_contents(self::core()->getDataPath() . "plugins/WorldEditor/data.yml", yaml_emit($this->data, YAML_UTF8_ENCODING));
        }
        self::core()->getPluginManager()->registerEvents($this, $this);
        self::core()->getLogger()->info(TextFormat::GOLD . "[WorldEditor]플러그인이 활성화 되었습니다");
    }

    public function PlayerInteractEvent(PlayerInteractEvent $ev){
        $item = $ev->getItem();
        $block = $ev->getBlock();
        $player = $ev->getPlayer();
        if(
            in_array($ev->getAction(), [PlayerInteractEvent::RIGHT_CLICK_AIR, PlayerInteractEvent::RIGHT_CLICK_BLOCK])
            && $ev->getFace() !== 255
        ){
            if($player->isOp() && $item->getID() === $this->data["tool-id"]){
                $player->sendMessage("[WorldEditor]Pos2 지역이 설정되었습니다 ({$block->x}, {$block->y}, {$block->z})");
                self::$pos[$player->getName()][1] = $block->floor();
                $ev->setCancelled();
                return;
            }
        }elseif(
            in_array($ev->getAction(), [PlayerInteractEvent::LEFT_CLICK_AIR, PlayerInteractEvent::LEFT_CLICK_BLOCK])
            && $player->isOp() && $item->getID() === $this->data["tool-id"]
        ){
            $player->sendMessage("[WorldEditor]Pos1 지역이 설정되었습니다 ({$block->x}, {$block->y}, {$block->z})");
            self::$pos[$player->getName()][0] = $block->floor();
            return;
        }
    }

    public function BlockBreakEvent(BlockBreakEvent $ev){
        $item = $ev->getItem();
        $block = $ev->getBlock();
        $player = $ev->getPlayer();
        if($player->isOp() && $item->getID() === $this->data["tool-id"]){
            $player->sendMessage("[WorldEditor]Pos1 지역이 설정되었습니다 ({$block->x}, {$block->y}, {$block->z})");
            self::$pos[$player->getName()][0] = $block->floor();
            $ev->setCancelled();
            return;
        }
    }

    public function setBlock($startX, $startY, $startZ, $endX, $endY, $endZ, Block $block, Player $player = null){
        $count = 0;
        $x = $startX;
        $y = $startY;
        $z = $startZ;
        if(is_array($y)){
            $startY = $y[1];
            $y = $y[0];
        }
        if(is_array($z)){
            $startZ = $z[1];
            $z = $z[0];
        }
        while(true){
            if(++$count <= $this->data["limit-block"]){
                $chunk = $block->getLevel()->getChunk($x >> 4, $z >> 4, true);
                if($chunk === null) return;
                $id = $chunk->getBlockId($x & 0x0f, $y & 0x7f, $z & 0x0f);
                $meta = $chunk->getBlockData($x & 0x0f, $y & 0x7f, $z & 0x0f);
                if($id !== $block->getId() or $meta !== $block->getDamage()){
                    if(!isset(self::$undo["$x:$y:$z"])) self::$undo["$x:$y:$z"] = [];
                    self::$undo["$x:$y:$z"][] = Block::get($id, $meta);
                    $block->getLevel()->setBlock(new Vector3($x, $y, $z), $block, false, false);
                }
                if($z < $endZ) $z++;
                else{
                    $z = $startZ;
                    if($y < $endY) $y++;
                    else{
                        $y = $startY;
                        if($x < $endX) $x++;
                        else break;
                    }
                }
            }else{
                self::core()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "setBlock"], [$x, [$y, $startY], [$z, $startZ], $endX, $endY, $endZ, $block, $player]), 1);
                return;
            }
        }
        if($player !== null){
            $player->sendMessage("[WorldEditor]모든 블럭을 설정했어요");
            self::core()->getLogger()->info("[WorldEditor]{$player->getName()}님이 블럭설정을 끝냇어요");
        }
    }

    public function replaceBlock($startX, $startY, $startZ, $endX, $endY, $endZ, Block $block, Block $target, Player $player = null){
        $count = 0;
        $x = $startX;
        $y = $startY;
        $z = $startZ;
        if(is_array($y)){
            $startY = $y[1];
            $y = $y[0];
        }
        if(is_array($z)){
            $startZ = $z[1];
            $z = $z[0];
        }
        while(true){
            if(++$count <= $this->data["limit-block"]){
                $chunk = $block->getLevel()->getChunk($x >> 4, $z >> 4, true);
                if($chunk === null) return;
                $id = $chunk->getBlockId($x & 0x0f, $y & 0x7f, $z & 0x0f);
                $meta = $chunk->getBlockData($x & 0x0f, $y & 0x7f, $z & 0x0f);
                if($id === $block->getId() or $meta === $block->getDamage()){
                    if(!isset(self::$undo["$x:$y:$z"])) self::$undo["$x:$y:$z"] = [];
                    self::$undo["$x:$y:$z"][] = $block;
                    $block->getLevel()->setBlock(new Vector3($x, $y, $z), $target, false, false);
                }
                if($z < $endZ) $z++;
                else{
                    $z = $startZ;
                    if($y < $endY) $y++;
                    else{
                        $y = $startY;
                        if($x < $endX) $x++;
                        else break;
                    }
                }
            }else{
                self::core()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "replaceBlock"], [$x, [$y, $startY], [$z, $startZ], $endX, $endY, $endZ, $block, $target, $player]), 1);
                return;
            }
        }
        if($player !== null){
            $player->sendMessage("[WorldEditor]모든 블럭을 변경했어요");
            self::core()->getLogger()->info("[WorldEditor]{$player->getName()}님이 블럭변경을 끝냇어요");
        }
    }

    public function undoBlock($startX, $startY, $startZ, $endX, $endY, $endZ, Player $player = null){
        $count = 0;
        $x = $startX;
        $y = $startY;
        $z = $startZ;
        if(is_array($y)){
            $startY = $y[1];
            $y = $y[0];
        }
        if(is_array($z)){
            $startZ = $z[1];
            $z = $z[0];
        }
        while(true){
            if(++$count <= $this->data["limit-block"]){
                if(!isset(self::$undo["$x:$y:$z"]) or count(self::$undo["$x:$y:$z"]) === 0) return;
                $block = array_pop(self::$undo["$x:$y:$z"]);
                if(!isset(self::$redo["$x:$y:$z"])) self::$redo["$x:$y:$z"] = [];
                self::$redo["$x:$y:$z"][] = $block;
                $block->getLevel()->setBlock(new Vector3($x, $y, $z), $block, false, false);
                if($z < $endZ) $z++;
                else{
                    $z = $startZ;
                    if($y < $endY) $y++;
                    else{
                        $y = $startY;
                        if($x < $endX) $x++;
                        else break;
                    }
                }
            }else{
                self::core()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "undoBlock"], [$x, [$y, $startY], [$z, $startZ], $endX, $endY, $endZ, $player]), 1);
                return;
            }
        }
        if($player !== null){
            $player->sendMessage("[WorldEditor]모든 블럭을 되돌렸어요");
            self::core()->getLogger()->info("[WorldEditor]{$player->getName()}님이 블럭 복구를 끝냈어요");
        }
    }

    public function redoBlock($startX, $startY, $startZ, $endX, $endY, $endZ, Player $player = null){
        $count = 0;
        $x = $startX;
        $y = $startY;
        $z = $startZ;
        if(is_array($y)){
            $startY = $y[1];
            $y = $y[0];
        }
        if(is_array($z)){
            $startZ = $z[1];
            $z = $z[0];
        }
        while(true){
            if(++$count <= $this->data["limit-block"]){
                if(!isset(self::$redo["$x:$y:$z"]) or count(self::$redo["$x:$y:$z"]) === 0) return;
                $block = array_pop(self::$redo["$x:$y:$z"]);
                if(!isset(self::$undo["$x:$y:$z"])) self::$undo["$x:$y:$z"] = [];
                self::$undo["$x:$y:$z"][] = $block;
                $block->getLevel()->setBlock(new Vector3($x, $y, $z), $block, false, false);
                if($z < $endZ) $z++;
                else{
                    $z = $startZ;
                    if($y < $endY) $y++;
                    else{
                        $y = $startY;
                        if($x < $endX) $x++;
                        else break;
                    }
                }
            }else{
                self::core()->getScheduler()->scheduleDelayedTask(new CallbackTask([$this, "redoBlock"], [$x, [$y, $startY], [$z, $startZ], $endX, $endY, $endZ, $player]), 1);
                return;
            }
        }
        if($player !== null){
            $player->sendMessage("[WorldEditor]모든 블럭을 다시 되돌렸어요");
            self::core()->getLogger()->info("[WorldEditor]{$player->getName()}님이 복구한 블럭을 모두 되돌렸어요");
        }
    }

    public function onCommand(CommandSender $i, Command $cmd, $label, array $sub){
        $output = "[WorldEditor]";
        if(!$i instanceof Player) return true;
        if(!isset(self::$pos[$i->getName()]) or count(self::$pos[$i->getName()]) < 2){
            $output .= "지역을 먼저 설정해주세요";
            $i->sendMessage($output);
            return true;
        }
        switch($cmd->getName()){
            case "/pos1":
                $pos = $i->floor();
                self::$pos[$i->getName()][0] = $pos;
                $output .= "Pos1 지점을 선택했어요 ({$pos->x}, {$pos->y}, {$pos->z})";
                break;
            case "/pos2":
                $pos = $i->floor();
                self::$pos[$i->getName()][1] = $pos;
                $output .= "Pos2 지점을 선택했어요 ({$pos->x}, {$pos->y}, {$pos->z})";
                break;
            case "/set":
                if(!isset($sub[0])){
                    $output .= "사용법: //set <id[:meta]>";
                    break;
                }
                $set = explode(":", $sub[0]);
                $block = self::$pos[$i->getName()];
                $endX = max($block[0]->x, $block[1]->x);
                $endY = max($block[0]->y, $block[1]->y);
                $endZ = max($block[0]->z, $block[1]->z);
                $startX = min($block[0]->x, $block[1]->x);
                $startY = min($block[0]->y, $block[1]->y);
                $startZ = min($block[0]->z, $block[1]->z);
                $this->setBlock($startX, $startY, $startZ, $endX, $endY, $endZ, Block::get($set[0], isset($set[1]) ? $set[1] : 0, $i->getPosition()), $i);
                $output .= "블럭 설정을 시작했어요";
                self::core()->getLogger()->info("[WorldEditor]{$i->getName()}님이 블럭설정을 시작했어요");
                break;
            case "/replace":
                if(!isset($sub[0]) or !isset($sub[1])){
                    $output .= "사용법: //replace <(선택)id[:meta]> <(바꿀)id[:meta>]";
                    break;
                }
                $get = explode(":", $sub[0]);
                $set = explode(":", $sub[1]);
                $block = self::$pos[$i->getName()];
                $endX = max($block["Pos1"]->x, $block["Pos2"]->x);
                $endY = max($block["Pos1"]->y, $block["Pos2"]->y);
                $endZ = max($block["Pos1"]->z, $block["Pos2"]->z);
                $startX = min($block["Pos1"]->x, $block["Pos2"]->x);
                $startY = min($block["Pos1"]->y, $block["Pos2"]->y);
                $startZ = min($block["Pos1"]->z, $block["Pos2"]->z);
                $this->replaceBlock($startX, $startY, $startZ, $endX, $endY, $endZ, Block::get($get[0], isset($get[1]) ? $get[1] : 0, $i->getPosition()), Block::get($set[0], isset($set[1]) ? $set[1] : 0, $i->getPosition()), $i);
                $output .= "블럭 변경을 시작했어요";
                self::core()->getLogger()->info("[WorldEditor]{$i->getDisplayName()}님이 블럭변경을 시작했어요");
                break;
            case "/undo":
                $block = self::$pos[$i->getName()];
                $endX = max($block[0]->x, $block[1]->x);
                $endY = max($block[0]->y, $block[1]->y);
                $endZ = max($block[0]->z, $block[1]->z);
                $startX = min($block[0]->x, $block[1]->x);
                $startY = min($block[0]->y, $block[1]->y);
                $startZ = min($block[0]->z, $block[1]->z);
                $this->undoBlock($startX, $startY, $startZ, $endX, $endY, $endZ, $i);
                $output .= "블럭을 되돌리는 중입니다";
                self::core()->getLogger()->info("[WorldEditor]{$i->getDisplayName()}님이 블럭을 복구하기 시작했어요");
                break;
            case "/redo":
                $block = self::$pos[$i->getName()];
                $endX = max($block[0]->x, $block[1]->x);
                $endY = max($block[0]->y, $block[1]->y);
                $endZ = max($block[0]->z, $block[1]->z);
                $startX = min($block[0]->x, $block[1]->x);
                $startY = min($block[0]->y, $block[1]->y);
                $startZ = min($block[0]->z, $block[1]->z);
                $this->undoBlock($startX, $startY, $startZ, $endX, $endY, $endZ, $i);
                $output .= "블럭 설정을 시작했어요";
                self::core()->getLogger()->info("[WorldEditor]{$i->getDisplayName()}님이 복구한 블럭을 되돌리기 시작했어요");
                break;
        }
        $i->sendMessage($output);
        return true;
    }

}
?>
