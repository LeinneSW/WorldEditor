<?php

namespace plugin;

use pocketmine\block\Air;
use pocketmine\block\Block;
use pocketmine\level\Position;
use pocketmine\Player;
use pocketmine\plugin\Plugin;
use pocketmine\scheduler\PluginTask;
use pocketmine\Server;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\event\Listener;
use pocketmine\command\Command;
use pocketmine\tile\Chest;
use pocketmine\tile\Tile;
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
    public static $copy = [];

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
                "limit-block" => 125,
                "debug" => false
            ];
            if(!is_dir($path = self::core()->getDataPath() . "plugins/WorldEditor/")) mkdir($path);
            file_put_contents(self::core()->getDataPath() . "plugins/WorldEditor/data.yml", yaml_emit($this->data, YAML_UTF8_ENCODING));
        }
        self::core()->getPluginManager()->registerEvents($this, $this);
        self::core()->getLogger()->info(TextFormat::GOLD . "[WorldEditor]플러그인이 활성화 되었습니다");
    }

    public function onDisable(){
        file_put_contents(self::core()->getDataPath() . "plugins/WorldEditor/data.yml", yaml_emit($this->data, YAML_UTF8_ENCODING));
    }

    /**
     * @param $data
     * @param mixed $default
     *
     * @return int|bool
     */
    public function getData($data, $default = false){
        if(!isset($this->data[$data])) $this->data[$data] = $default;
        return $this->data[$data];
    }

    public function PlayerInteractEvent(PlayerInteractEvent $ev){
        $item = $ev->getItem();
        $block = $ev->getBlock();
        $player = $ev->getPlayer();
        if(
            in_array($ev->getAction(), [PlayerInteractEvent::RIGHT_CLICK_AIR, PlayerInteractEvent::RIGHT_CLICK_BLOCK])
            && $ev->getFace() !== 255
        ){
            if($player->isOp() && $item->getID() === $this->getData("tool-id", Item::IRON_HOE)){
                $player->sendMessage("[WorldEditor]Pos2 지점을 선택했어요 ({$block->x}, {$block->y}, {$block->z})");
                self::$pos[$player->getName()][1] = $block->floor();
                $ev->setCancelled();
                return;
            }
        }elseif(
            in_array($ev->getAction(), [PlayerInteractEvent::LEFT_CLICK_AIR, PlayerInteractEvent::LEFT_CLICK_BLOCK])
            && $player->isOp() && $item->getID() === $this->getData("tool-id", Item::IRON_HOE)
        ){
            $player->sendMessage("[WorldEditor]Pos1 지점을 선택했어요 ({$block->x}, {$block->y}, {$block->z})");
            self::$pos[$player->getName()][0] = $block->floor();
            return;
        }
    }

    public function BlockBreakEvent(BlockBreakEvent $ev){
        $item = $ev->getItem();
        $block = $ev->getBlock();
        $player = $ev->getPlayer();
        if($player->isOp() && $item->getID() === $this->getData("tool-id", Item::IRON_HOE)){
            $player->sendMessage("[WorldEditor]Pos1 지점을 선택했어요 ({$block->x}, {$block->y}, {$block->z})");
            self::$pos[$player->getName()][0] = $block->floor();
            $ev->setCancelled();
            return;
        }
    }

    /**
     * @param Block $block
     * @param Vector3 $pos
     *
     * @return bool
     */
    public function saveUndo(Block $block, Vector3 $pos = null){
        if($block->getLevel() === null) return false;
        if($pos !== null) $block->setComponents($pos->x, $pos->y, $pos->z);
        $key = "{$block->x}:{$block->y}:{$block->z}";
        if(!isset(self::$undo[$key])) self::$undo[$key] = [];
        self::$undo[$key][] = $block;
        return true;
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
            if($count < $this->getData("limit-block", 125)){
                $chunk = $block->getLevel()->getChunk($x >> 4, $z >> 4, true);
                if($chunk !== null){
                    $id = $chunk->getBlockId($x & 0x0f, $y & 0x7f, $z & 0x0f);
                    $meta = $chunk->getBlockData($x & 0x0f, $y & 0x7f, $z & 0x0f);
                    if($id !== $block->getId() or $meta !== $block->getDamage()){
                        ++$count;
                        $undo = Block::get($id, $meta);
                        $undo->level = $block->getLevel();
                        $this->saveUndo($undo, $pos = new Vector3($x, $y, $z));
                        $tile = $block->getLevel()->getTile($pos);
                        if($tile instanceof Chest) $tile->unpair();
                        if($tile instanceof Tile) $tile->close();
                        $block->getLevel()->setBlock($pos, $block, false, false);
                    }
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
                self::core()->getScheduler()->scheduleDelayedTask(new WorldEditorTask([$this, "setBlock"], [$x, [$y, $startY], [$z, $startZ], $endX, $endY, $endZ, $block, $player], $this), 1);
                return;
            }
        }
        if($player !== null) $player->sendMessage("[WorldEditor]모든 블럭을 설정했어요");
        if($this->getData("debug", false)){
            $name = $player === null ? "" : "{$player->getName()}님이 ";
            self::core()->getLogger()->info("[WorldEditor]{$name}블럭설정을 끝냇어요");
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
            if($count < $this->getData("limit-block", 125)){
                $chunk = $block->getLevel()->getChunk($x >> 4, $z >> 4, true);
                if($chunk !== null){
                    $id = $chunk->getBlockId($x & 0x0f, $y & 0x7f, $z & 0x0f);
                    $meta = $chunk->getBlockData($x & 0x0f, $y & 0x7f, $z & 0x0f);
                    if($id === $block->getId() or $meta === $block->getDamage()){
                        ++$count;
                        $this->saveUndo($block, $pos = new Vector3($x, $y, $z));
                        $tile = $block->getLevel()->getTile($pos);
                        if($tile instanceof Chest) $tile->unpair();
                        if($tile instanceof Tile) $tile->close();
                        $block->getLevel()->setBlock(new Vector3($x, $y, $z), $target, false, false);
                    }
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
                self::core()->getScheduler()->scheduleDelayedTask(new WorldEditorTask([$this, "replaceBlock"], [$x, [$y, $startY], [$z, $startZ], $endX, $endY, $endZ, $block, $target, $player], $this), 1);
                return;
            }
        }
        if($player !== null) $player->sendMessage("[WorldEditor]모든 블럭을 변경했어요");
        if($this->getData("debug", false)){
            $name = $player === null ? "" : "{$player->getName()}님이 ";
            self::core()->getLogger()->info("[WorldEditor]{$name}블럭변경을 끝냇어요");
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
            if($count < $this->getData("limit-block", 125)){
                if(isset(self::$undo["$x:$y:$z"])){
                    ++$count;
                    /** @var Block $block */
                    $block = array_pop(self::$undo["$x:$y:$z"]);
                    self::$redo["$x:$y:$z"][] = $block;
                    $tile = $block->getLevel()->getTile($pos = new Vector3($x, $y, $z));
                    if($tile instanceof Chest) $tile->unpair();
                    if($tile instanceof Tile) $tile->close();
                    $block->getLevel()->setBlock($pos, $block, false, false);
                    if(count(self::$undo["$x:$y:$z"]) === 0) unset(self::$undo["$x:$y:$z"]);
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
                self::core()->getScheduler()->scheduleDelayedTask(new WorldEditorTask([$this, "undoBlock"], [$x, [$y, $startY], [$z, $startZ], $endX, $endY, $endZ, $player], $this), 1);
                return;
            }
        }
        if($player !== null) $player->sendMessage("[WorldEditor]모든 블럭을 되돌렸어요");
        if($this->getData("debug", false)){
            $name = $player === null ? "" : "{$player->getName()}님이 ";
            self::core()->getLogger()->info("[WorldEditor]{$name}블럭 복구를 끝냈어요");
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
            if($count < $this->getData("limit-block", 125)){
                if(isset(self::$redo["$x:$y:$z"])){
                    ++$count;
                    /** @var Block $block */
                    $block = array_pop(self::$redo["$x:$y:$z"]);
                    $this->saveUndo($block, $pos = new Vector3($x, $y, $z));
                    $tile = $block->getLevel()->getTile($pos);
                    if($tile instanceof Chest) $tile->unpair();
                    if($tile instanceof Tile) $tile->close();
                    $block->getLevel()->setBlock($pos, $block, false, false);
                    if(count(self::$redo["$x:$y:$z"]) === 0) unset(self::$redo["$x:$y:$z"]);
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
                self::core()->getScheduler()->scheduleDelayedTask(new WorldEditorTask([$this, "redoBlock"], [$x, [$y, $startY], [$z, $startZ], $endX, $endY, $endZ, $player], $this), 1);
                return;
            }
        }
        if($player !== null) $player->sendMessage("[WorldEditor]모든 블럭을 다시 되돌렸어요");
        if($this->getData("debug", false)){
            $name = $player === null ? "" : "{$player->getName()}님이 ";
            self::core()->getLogger()->info("[WorldEditor]{$name}복구한 블럭을 모두 되돌렸어요");
        }
    }

    public function copyBlock($startX, $startY, $startZ, $endX, $endY, $endZ, Player $player){
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
            if($count < $this->getData("limit-block", 125) * 6){
                $chunk = $player->getLevel()->getChunk($x >> 4, $z >> 4, true);
                if($chunk !== null){
                    ++$count;
                    if(!isset(self::$copy[$player->getName()])) self::$copy[$player->getName()] = [];
                    $id = $chunk->getBlockId($x & 0x0f, $y & 0x7f, $z & 0x0f);
                    $meta = $chunk->getBlockData($x & 0x0f, $y & 0x7f, $z & 0x0f);
                    if($id !== 0){
                        $block = Block::get($id, $meta);
                        $block->add($x - $startX, $y - $startY, $z - $startZ);
                        $block->level = $player->getLevel();
                        self::$copy[$player->getName()][] = $block;
                    }
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
                self::core()->getScheduler()->scheduleDelayedTask(new WorldEditorTask([$this, "copyBlock"], [$x, [$y, $startY], [$z, $startZ], $endX, $endY, $endZ, $player], $this), 1);
                return;
            }
        }
        if($player !== null){
            $player->sendMessage("[WorldEditor]모든 블럭을 복사했어요");
        }
        if($this->getData("debug", false)){
            $name = $player === null ? "" : "{$player->getName()}님이 ";
            self::core()->getLogger()->info("[WorldEditor]{$name}블럭을 복사했어요");
        }
    }

    public function pasteBlock(Position $pos, Player $player = null){
        $count = 0;
        foreach(self::$copy[$player->getName()] as $key => $block){
            /** @var Block $block */
            if(++$count <= $this->getData("limit-block", 125)){
                $p = $block->add($pos);
                $chunk = $block->getLevel()->getChunk($p->x >> 4, $p->z >> 4, true);
                $id = $chunk->getBlockId($p->x & 0x0f, $p->y & 0x7f, $p->z & 0x0f);
                $meta = $chunk->getBlockData($p->x & 0x0f, $p->y & 0x7f, $p->z & 0x0f);
                if($block->getId() !== Item::AIR){
                    $tar = Block::get($id, $meta);
                    $tar->level = $block->getLevel();
                    $this->saveUndo($block, $p);
                    $tile = $block->getLevel()->getTile($p);
                    if($tile instanceof Chest) $tile->unpair();
                    if($tile instanceof Tile) $tile->close();
                    $pos->getLevel()->setBlock($p, $block, false, false);
                }
                unset(self::$copy[$player->getName()][$key]);
            }else{
                self::core()->getScheduler()->scheduleDelayedTask(new WorldEditorTask([$this, "pasteBlock"], [$pos, $player], $this), 1);
                return;
            }
        }
        if($player !== null) $player->sendMessage("[WorldEditor]모든 블럭을 붙여넣었어요");
        if($this->getData("debug", false)){
            $name = $player === null ? "" : "{$player->getName()}님이 ";
            self::core()->getLogger()->info("[WorldEditor]}$name 블럭을 붙여넣었어요");
        }
    }

    public function cutBlock($startX, $startY, $startZ, $endX, $endY, $endZ, Player $player){
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
            if($count < $this->getData("limit-block", 125)){
                $chunk = $player->getLevel()->getChunk($x >> 4, $z >> 4, true);
                if($chunk !== null){
                    ++$count;
                    $block = Block::get(
                        $chunk->getBlockId($x & 0x0f, $y & 0x7f, $z & 0x0f),
                        $chunk->getBlockData($x & 0x0f, $y & 0x7f, $z & 0x0f),
                        new Position($x - $startX, $y - $startY, $z - $startZ, $player->getLevel())
                    );
                    if(!isset(self::$copy[$player->getName()])) self::$copy[$player->getName()] = [];
                    self::$copy[$player->getName()][] = $block;
                    $this->saveUndo($block);
                    $tile = $block->getLevel()->getTile($block);
                    if($tile instanceof Chest) $tile->unpair();
                    if($tile instanceof Tile) $tile->close();
                    $block->getLevel()->setBlock(new Vector3($x, $y, $z), new Air(), false, false);
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
                self::core()->getScheduler()->scheduleDelayedTask(new WorldEditorTask([$this, "copyBlock"], [$x, [$y, $startY], [$z, $startZ], $endX, $endY, $endZ, $player], $this), 1);
                return;
            }
        }
        if($player !== null) $player->sendMessage("[WorldEditor]모든 블럭을 복사했어요");
        if($this->getData("debug", false)){
            $name = $player === null ? "" : "{$player->getName()}님이 ";
            self::core()->getLogger()->info("[WorldEditor]{$name}블럭을 복사했어요");
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
                $output .= "블럭 설정을 시작했어요";
                if($this->getData("debug", false)) self::core()->getLogger()->info("[WorldEditor]{$i->getName()}님이 블럭설정을 시작했어요");
                $callback = "setBlock";
                $params = [$startX, $startY, $startZ, $endX, $endY, $endZ, Block::get($set[0], isset($set[1]) ? $set[1] : 0, $i->getPosition()), $i];
                break;
            case "/replace":
                if(!isset($sub[0]) or !isset($sub[1])){
                    $output .= "사용법: //replace <(선택)id[:meta]> <(바꿀)id[:meta>]";
                    break;
                }
                $get = explode(":", $sub[0]);
                $set = explode(":", $sub[1]);
                $block = self::$pos[$i->getName()];
                $endX = max($block[0]->x, $block[1]->x);
                $endY = max($block[0]->y, $block[1]->y);
                $endZ = max($block[0]->z, $block[1]->z);
                $startX = min($block[0]->x, $block[1]->x);
                $startY = min($block[0]->y, $block[1]->y);
                $startZ = min($block[0]->z, $block[1]->z);
                $output .= "블럭 변경을 시작했어요";
                if($this->getData("debug", false)) self::core()->getLogger()->info("[WorldEditor]{$i->getName()}님이 블럭변경을 시작했어요");
                $callback = "replaceBlock";
                $params = [$startX, $startY, $startZ, $endX, $endY, $endZ, Block::get($get[0], isset($get[1]) ? $get[1] : 0, $i->getPosition()), Block::get($set[0], isset($set[1]) ? $set[1] : 0, $i->getPosition()), $i];
                break;
            case "/undo":
                $block = self::$pos[$i->getName()];
                $endX = max($block[0]->x, $block[1]->x);
                $endY = max($block[0]->y, $block[1]->y);
                $endZ = max($block[0]->z, $block[1]->z);
                $startX = min($block[0]->x, $block[1]->x);
                $startY = min($block[0]->y, $block[1]->y);
                $startZ = min($block[0]->z, $block[1]->z);
                $output .= "블럭을 되돌리는 중입니다";
                if($this->getData("debug", false)) self::core()->getLogger()->info("[WorldEditor]{$i->getName()}님이 블럭을 복구하기 시작했어요");
                $callback = "undoBlock";
                $params = [$startX, $startY, $startZ, $endX, $endY, $endZ, $i];
                break;
            case "/redo":
                $block = self::$pos[$i->getName()];
                $endX = max($block[0]->x, $block[1]->x);
                $endY = max($block[0]->y, $block[1]->y);
                $endZ = max($block[0]->z, $block[1]->z);
                $startX = min($block[0]->x, $block[1]->x);
                $startY = min($block[0]->y, $block[1]->y);
                $startZ = min($block[0]->z, $block[1]->z);
                $output .= "블럭 설정을 시작했어요";
                if($this->getData("debug", false)) self::core()->getLogger()->info("[WorldEditor]{$i->getName()}님이 복구한 블럭을 되돌리기 시작했어요");
                $callback = "redoBlock";
                $params = [$startX, $startY, $startZ, $endX, $endY, $endZ, $i];
                break;
            case "/copy":
                $block = self::$pos[$i->getName()];
                $endX = max($block[0]->x, $block[1]->x);
                $endY = max($block[0]->y, $block[1]->y);
                $endZ = max($block[0]->z, $block[1]->z);
                $startX = min($block[0]->x, $block[1]->x);
                $startY = min($block[0]->y, $block[1]->y);
                $startZ = min($block[0]->z, $block[1]->z);
                $output .= "블럭 복사를 시작했어요";
                if($this->getData("debug", false)) self::core()->getLogger()->info("[WorldEditor]{$i->getName()}님이 블럭 복사를 시작했어요");
                $this->copyBlock($startX, $startY, $startZ, $endX, $endY, $endZ, $i);
                break;
            case "/paste":
                $output .= "블럭 붙여넣기를 시작했어요";
                if($this->getData("debug", false)) self::core()->getLogger()->info("[WorldEditor]{$i->getName()}님이 블럭 붙여넣기를 시작했어요");
                $callback = "pasteBlock";
                $params = [$i->getPosition(), $i];
                break;
            case "/cut":
                $block = self::$pos[$i->getName()];
                $endX = max($block[0]->x, $block[1]->x);
                $endY = max($block[0]->y, $block[1]->y);
                $endZ = max($block[0]->z, $block[1]->z);
                $startX = min($block[0]->x, $block[1]->x);
                $startY = min($block[0]->y, $block[1]->y);
                $startZ = min($block[0]->z, $block[1]->z);
                $output .= "블럭 복사를 시작했어요";
                if($this->getData("debug", false)) self::core()->getLogger()->info("[WorldEditor]{$i->getName()}님이 블럭 복사를 시작했어요");
                $callback = "cutBlock";
                $params = [$startX, $startY, $startZ, $endX, $endY, $endZ, $i];
                break;
        }
        if($output !== "[WorldEditor]") $i->sendMessage($output);
        if(isset($callback) && isset($params) && is_array($params)) $this->{$callback}(...$params);
        return true;
    }
}

class WorldEditorTask extends PluginTask{

    protected $callable;
    protected $args;

    public function __construct(callable $callable, array $args = [], Plugin $owner){
        $this->callable = $callable;
        $this->owner = $owner;
        $this->args = $args;
    }

    /**
     * @return callable
     */
    public function getCallable(){
        return $this->callable;
    }

    public function onRun($currentTicks){
        call_user_func_array($this->callable, $this->args);
    }

}