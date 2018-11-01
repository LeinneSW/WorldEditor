<?php

declare(strict_types=1);

namespace leinne\worldeditor;

use milk\worldeditor\task\CallbackTask;
use pocketmine\block\Air;
use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\item\ItemFactory;
use pocketmine\level\Level;
use pocketmine\level\Position;
use pocketmine\Player;
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

    /** @var WorldEditor */
    private static $instance;

    /** @var Item */
    private $tool;

    private $pos = [];
    private $data = [];

    private $copy = [], $undo = [], $redo = [];

    public static function getInstance() : WorldEditor{
        return self::$instance;
    }

    public function onEnable() : void{
        self::$instance = $this;
        $this->saveDefaultConfig();
        
        $this->data = $this->getConfig()->getAll();
        $this->tool = ItemFactory::get($this->data["tool-id"] ?? Item::IRON_HOE, $this->data["tool-meta"] ?? 0);
        
        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getLogger()->info(TextFormat::GOLD . "[WorldEditor]플러그인이 활성화 되었습니다");
    }

    public function onDisable() : void{
        $this->getServer()->getLogger()->info(TextFormat::GOLD . "[WorldEditor]플러그인이 비활성화 되었습니다");
    }

    public function getLimit() : int{
        return $this->data["limit-block"] ?? 130;
    }
    
    public function canSetting(Player $player) : bool{
        $data = $this->pos[$player->getName()] ?? [];
        return \count($data) > 1 && $data[0]->level === $data[1]->level;
    }

    public function onPlayerInteractEvent(PlayerInteractEvent $ev) : void{
        $block = $ev->getBlock();
        $player = $ev->getPlayer();
        if($player->hasPermission("worldeditor.command.setpos") && $ev->getItem()->equals($this->tool)){
            $ev->setCancelled();
            if($ev->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK){
                $this->pos[$player->getName()][0] = $block->floor();
                $player->sendMessage("[WorldEditor]Pos1 지점을 선택했어요 ({$block->x}, {$block->y}, {$block->z}, {$block->level->getFolderName()})");
            }else{
                $this->pos[$player->getName()][1] = $block->floor();
                $player->sendMessage("[WorldEditor]Pos2 지점을 선택했어요 ({$block->x}, {$block->y}, {$block->z}, {$block->level->getFolderName()})");
            }
        }
    }

    public function onBlockBreakEvent(BlockBreakEvent $ev) : void{
        $block = $ev->getBlock();
        $player = $ev->getPlayer();
        if($player->hasPermission("worldeditor.command.setpos") && $ev->getItem()->equals($this->tool)){
            $ev->setCancelled();
            $this->pos[$player->getName()][0] = $block->floor();
            $player->sendMessage("[WorldEditor]Pos1 지점을 선택했어요 ({$block->x}, {$block->y}, {$block->z})");
            return;
        }
    }

    public function saveUndo(Block $block, ?Vector3 $pos = \null, ?Level $level = \null) : bool{
        if($pos instanceof Position && $pos->level !== \null){
            $block->level = $pos->level;
        }elseif($level !== \null){
            $block->level = $level;
        }elseif($block->level === \null){
            return \false;
        }
        if($pos !== \null){
            $block->setComponents($pos->x, $pos->y, $pos->z);
        }
        $key = "{$block->x}:{$block->y}:{$block->z}";
        if(!isset($this->undo[$key])){
            $this->undo[$key] = [];
        }
        $this->undo[$key][] = $block;
        return \true;
    }

    public function saveRedo(Block $block, ?Vector3 $pos = \null, ?Level $level = \null) : bool{
        if($pos instanceof Position && $pos->level !== \null){
            $block->level = $pos->level;
        }elseif($level !== \null){
            $block->level = $level;
        }elseif($block->level === \null){
            return \false;
        }
        if($pos !== \null){
            $block->setComponents($pos->x, $pos->y, $pos->z);
        }
        $key = "{$block->x}:{$block->y}:{$block->z}";
        if(!isset($this->redo[$key])){
            $this->redo[$key] = [];
        }
        $this->redo[$key][] = $block;
        return \true;
    }

    public function saveCopy(int $id, int $meta, Player $player, Vector3 $pos) : bool{
        if($id === Block::AIR){
            return \false;
        }

        if(!isset($this->copy[$player->getName()])){
            $this->copy[$player->getName()] = [];
        }
        $this->copy[$player->getName()][] = "$id, $meta, $pos->x, $pos->y, $pos->z";
        return \true;
    }

    public function set(Block $block, Vector3 $pos, ?Level $level = \null) : void{
        if($pos instanceof Position && $pos->level !== \null){
            $level = $pos->level;
        }elseif($block->level !== \null){
            $level = $block->level;
        }elseif($level === \null){
            return;
        }
        $tile = $level->getTile($pos);
        if($tile instanceof Chest){
            $tile->unpair();
        }
        if($tile instanceof Tile){
            $tile->close();
        }
        $level->setBlock($pos, $block, \false);
    }

    public function setBlock($startX, $startY, $startZ, $endX, $endY, $endZ, Block $block, ?Player $player = \null) : void{
        $count = 0;
        $x = $startX;
        $y = $startY;
        $z = $startZ;
        if(\is_array($y)){
            $startY = $y[1];
            $y = $y[0];
        }
        if(\is_array($z)){
            $startZ = $z[1];
            $z = $z[0];
        }
        while(\true){
            if($count < $this->getLimit()){
                $chunk = $block->level->getChunk($x >> 4, $z >> 4, \true);
                if($chunk !== \null){
                    $id = $chunk->getBlockId($x & 0x0f, $y & 0x7f, $z & 0x0f);
                    $meta = $chunk->getBlockData($x & 0x0f, $y & 0x7f, $z & 0x0f);
                    if($id !== $block->getId() or $meta !== $block->getDamage()){
                        ++$count;
                        $this->saveUndo(BlockFactory::get($id, $meta), $pos = new Position($x, $y, $z, $block->level));
                        $this->set($block, $pos);
                    }
                }
                if($z < $endZ) ++$z;
                else{
                    $z = $startZ;
                    if($y < $endY) ++$y;
                    else{
                        $y = $startY;
                        if($x < $endX) ++$x;
                        else break;
                    }
                }
            }else{
                $this->getScheduler()->scheduleDelayedTask(new CallbackTask("setBlock", [$x, [$y, $startY], [$z, $startZ], $endX, $endY, $endZ, $block, $player]), 1);
                return;
            }
        }

        if($player !== \null){
            $player->sendMessage("[WorldEditor]모든 블럭을 설정했어요");
        }
    }

    public function replaceBlock($startX, $startY, $startZ, $endX, $endY, $endZ, Block $block, Block $target, ?Player $player = \null) : void{
        $count = 0;
        $x = $startX;
        $y = $startY;
        $z = $startZ;
        if(\is_array($y)){
            $startY = $y[1];
            $y = $y[0];
        }
        if(\is_array($z)){
            $startZ = $z[1];
            $z = $z[0];
        }
        while(\true){
            if($count < $this->getLimit()){
                $chunk = $block->level->getChunk($x >> 4, $z >> 4, \true);
                if($chunk !== \null){
                    $id = $chunk->getBlockId($x & 0x0f, $y & 0x7f, $z & 0x0f);
                    $meta = $chunk->getBlockData($x & 0x0f, $y & 0x7f, $z & 0x0f);
                    if($id === $block->getId() && $meta === $block->getDamage()){
                        ++$count;
                        $this->saveUndo($block, $pos = new Vector3($x, $y, $z));
                        $this->set($target, $pos);
                    }
                }
                if($z < $endZ) ++$z;
                else{
                    $z = $startZ;
                    if($y < $endY) ++$y;
                    else{
                        $y = $startY;
                        if($x < $endX) ++$x;
                        else break;
                    }
                }
            }else{
                $this->getScheduler()->scheduleDelayedTask(new CallbackTask("replaceBlock", [$x, [$y, $startY], [$z, $startZ], $endX, $endY, $endZ, $block, $target, $player]), 1);
                return;
            }
        }

        if($player !== \null){
            $player->sendMessage("[WorldEditor]모든 블럭을 변경했어요");
        }
    }

    public function undoBlock(array $xyz, Vector3 $spos, Vector3 $epos, Level $level, ?Player $player = \null) : void{
        $count = 0;
        $x = $xyz[0];
        $y = $xyz[1];
        $z = $xyz[2];
        while(\true){
            if($count < $this->getLimit()){
                if(isset($this->undo["$x:$y:$z"])){
                    ++$count;
                    /** @var Block $block */
                    $block = \array_pop($this->undo["$x:$y:$z"]);
                    $pos = new Vector3($x, $y, $z);
                    $this->saveRedo($block->level->getBlock($pos), $pos);
                    $this->set($block, $pos);
                    if(count($this->undo["$x:$y:$z"]) === 0){
                        unset($this->undo["$x:$y:$z"]);
                    }
                }
                if($z < $epos->z) ++$z;
                else{
                    $z = $spos->z;
                    if($y < $epos->y) ++$y;
                    else{
                        $y = $spos->y;
                        if($x < $epos->x) ++$x;
                        else break;
                    }
                }
            }else{
                $this->getScheduler()->scheduleDelayedTask(new CallbackTask("undoBlock", [[$x, $y, $z], $spos, $epos, $level, $player]), 1);
                return;
            }
        }

        if($player !== \null){
            $player->sendMessage("[WorldEditor]모든 블럭을 되돌렸어요");
        }
    }

    public function redoBlock($startX, $startY, $startZ, $endX, $endY, $endZ, ?Player $player = \null) : void{
        $count = 0;
        $x = $startX;
        $y = $startY;
        $z = $startZ;
        if(\is_array($y)){
            $startY = $y[1];
            $y = $y[0];
        }
        if(\is_array($z)){
            $startZ = $z[1];
            $z = $z[0];
        }
        while(\true){
            if($count < $this->getLimit()){
                if(isset($this->redo["$x:$y:$z"])){
                    ++$count;
                    /** @var Block $block */
                    $block = \array_pop($this->redo["$x:$y:$z"]);
                    $pos = new Vector3($x, $y, $z);
                    $this->saveUndo($block->level->getBlock($pos), $pos);
                    $this->set($block, $pos);
                    if(\count($this->redo["$x:$y:$z"]) === 0){
                        unset($this->redo["$x:$y:$z"]);
                    }
                }
                if($z < $endZ) ++$z;
                else{
                    $z = $startZ;
                    if($y < $endY) ++$y;
                    else{
                        $y = $startY;
                        if($x < $endX) ++$x;
                        else break;
                    }
                }
            }else{
                $this->getScheduler()->scheduleDelayedTask(new CallbackTask("redoBlock", [$x, [$y, $startY], [$z, $startZ], $endX, $endY, $endZ, $player]), 1);
                return;
            }
        }

        if($player !== \null){
            $player->sendMessage("[WorldEditor]모든 블럭을 다시 되돌렸어요");
        }
    }

    public function copyBlock($startX, $startY, $startZ, $endX, $endY, $endZ, Player $player) : void{
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
        while(\true){
            $chunk = $player->level->getChunk($x >> 4, $z >> 4, \true);
            if(
                $chunk !== \null
                && $this->saveCopy($player->level->getBlockIdAt($x, $y, $z), $player->level->getBlockDataAt($x, $y, $z), $player, new Vector3($x - $startX, $y - $startY, $z - $startZ))
            ){
                ++$count;
            }

            if($z < $endZ) ++$z;
            else{
                $z = $startZ;
                if($y < $endY) ++$y;
                else{
                    $y = $startY;
                    if($x < $endX) ++$x;
                    else break;
                }
            }
        }
        $player->sendMessage("[WorldEditor]모든 블럭을 복사했어요");
    }

    public function pasteBlock(Vector3 $pos, Player $player) : void{
        $count = 0;
        while(\true){
            if($count < $this->getLimit()){
                if(isset($this->copy[$player->getName()]) && ($data = \array_pop($this->copy[$player->getName()]))){
                    ++$count;
                    $data = \explode(", ", $data);
                    $block = BlockFactory::get($data[0], $data[1]);
                    $block->setComponents($data[2] + $pos->x, $data[3] + $pos->y, $data[4] + $pos->z);
                    $this->saveUndo($player->level->getBlock($block), $block, $player->level);
                    $this->set($block, $block, $player->level);
                }else{
                    break;
                }
            }else{
                $this->getScheduler()->scheduleDelayedTask(new CallbackTask("pasteBlock", [$pos, $player]), 1);
                return;
            }
        }
        $player->sendMessage("[WorldEditor]모든 블럭을 붙여넣었어요");
    }

    public function cutBlock($startX, $startY, $startZ, $endX, $endY, $endZ, Player $player) : void{
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
        while(\true){
            if($count < $this->getLimit()){
                $chunk = $player->level->getChunk($x >> 4, $z >> 4, \true);
                if($chunk !== \null){
                    ++$count;
                    $block = BlockFactory::get(
                        $chunk->getBlockId($x & 0x0f, $y & 0x7f, $z & 0x0f),
                        $chunk->getBlockData($x & 0x0f, $y & 0x7f, $z & 0x0f),
                        new Position($x - $startX, $y - $startY, $z - $startZ, $player->level)
                    );
                    if(!isset($this->copy[$player->getName()])) $this->copy[$player->getName()] = [];
                    $this->saveCopy($block->getId(), $block->getDamage(), $player, new Position($x - $startX, $y - $startY, $z - $startZ, $player->level));
                    $this->saveUndo($block);
                    $this->set(new Air(), $block);
                }

                if($z < $endZ) ++$z;
                else{
                    $z = $startZ;
                    if($y < $endY) ++$y;
                    else{
                        $y = $startY;
                        if($x < $endX) ++$x;
                        else break;
                    }
                }
            }else{
                $this->getScheduler()->scheduleDelayedTask(new CallbackTask("copyBlock", [$x, [$y, $startY], [$z, $startZ], $endX, $endY, $endZ, $player]), 1);
                return;
            }
        }
        $player->sendMessage("[WorldEditor]모든 블럭을 복사했어요");
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $sub) : bool{
        if(!($sender instanceof Player)){
            return \true;
        }

        switch($cmd->getName()){
            case "/pos1":
                $pos = $sender->floor();
                $this->pos[$sender->getName()][0] = $pos;
                $output = "Pos1 지점을 선택했어요 ({$pos->x}, {$pos->y}, {$pos->z})";
                break;
            case "/pos2":
                $pos = $sender->floor();
                $this->pos[$sender->getName()][1] = $pos;
                $output = "Pos2 지점을 선택했어요 ({$pos->x}, {$pos->y}, {$pos->z})";
                break;
            case "/set":
                if(!isset($sub[0])){
                    $output = "사용법: //set <id[:meta]>";
                    break;
                }
                if(!$this->canSetting($sender)){
                    $output = "지역을 먼저 설정해주세요";
                    break;
                }
                $set = \explode(":", $sub[0]);
                $block = $this->pos[$sender->getName()];
                $endX = \max($block[0]->x, $block[1]->x);
                $endY = \max($block[0]->y, $block[1]->y);
                $endZ = \max($block[0]->z, $block[1]->z);
                $startX = \min($block[0]->x, $block[1]->x);
                $startY = \min($block[0]->y, $block[1]->y);
                $startZ = \min($block[0]->z, $block[1]->z);
                $output = "블럭 설정을 시작했어요";
                $callback = "setBlock";
                $params = [$startX, $startY, $startZ, $endX, $endY, $endZ, BlockFactory::get($set[0], isset($set[1]) ? $set[1] : 0, $sender->getPosition()), $sender];
                break;
            case "/replace":
                if(!isset($sub[0]) or !isset($sub[1])){
                    $output = "사용법: //replace <(선택)id[:meta]> <(바꿀)id[:meta>]";
                    break;
                }
                if(!$this->canSetting($sender)){
                    $output = "지역을 먼저 설정해주세요";
                    break;
                }
                $get = \explode(":", $sub[0]);
                $set = \explode(":", $sub[1]);
                $block = $this->pos[$sender->getName()];
                $endX = \max($block[0]->x, $block[1]->x);
                $endY = \max($block[0]->y, $block[1]->y);
                $endZ = \max($block[0]->z, $block[1]->z);
                $startX = \min($block[0]->x, $block[1]->x);
                $startY = \min($block[0]->y, $block[1]->y);
                $startZ = \min($block[0]->z, $block[1]->z);
                $output = "블럭 변경을 시작했어요";
                $callback = "replaceBlock";
                $params = [$startX, $startY, $startZ, $endX, $endY, $endZ, BlockFactory::get($get[0], isset($get[1]) ? $get[1] : 0, $sender->getPosition()), BlockFactory::get($set[0], isset($set[1]) ? $set[1] : 0, $sender->getPosition()), $sender];
                break;
            case "/undo":
                if(!$this->canSetting($sender)){
                    $output = "지역을 먼저 설정해주세요";
                    break;
                }
                $block = $this->pos[$sender->getName()];
                $spos = new Vector3(\min($block[0]->x, $block[1]->x), \min($block[0]->y, $block[1]->y), \min($block[0]->z, $block[1]->z));
                $epos = new Vector3(\max($block[0]->x, $block[1]->x), \max($block[0]->y, $block[1]->y), \max($block[0]->z, $block[1]->z));
                $output = "블럭을 되돌리는 중입니다";
                $callback = "undoBlock";
                $params = [[$spos->x, $spos->y, $spos->z], $spos, $epos, $block[0]->level, $sender];
                break;
            case "/redo":
                if(!$this->canSetting($sender)){
                    $output = "지역을 먼저 설정해주세요";
                    break;
                }
                $block = $this->pos[$sender->getName()];
                $endX = \max($block[0]->x, $block[1]->x);
                $endY = \max($block[0]->y, $block[1]->y);
                $endZ = \max($block[0]->z, $block[1]->z);
                $startX = \min($block[0]->x, $block[1]->x);
                $startY = \min($block[0]->y, $block[1]->y);
                $startZ = \min($block[0]->z, $block[1]->z);
                $output = "블럭 설정을 시작했어요";
                $callback = "redoBlock";
                $params = [$startX, $startY, $startZ, $endX, $endY, $endZ, $sender];
                break;
            case "/copy":
                if(!$this->canSetting($sender)){
                    $output = "지역을 먼저 설정해주세요";
                    break;
                }
                $block = $this->pos[$sender->getName()];
                $endX = \max($block[0]->x, $block[1]->x);
                $endY = \max($block[0]->y, $block[1]->y);
                $endZ = \max($block[0]->z, $block[1]->z);
                $startX = \min($block[0]->x, $block[1]->x);
                $startY = \min($block[0]->y, $block[1]->y);
                $startZ = \min($block[0]->z, $block[1]->z);
                $output = "블럭 복사를 시작했어요";
                $callback = "copyBlock";
                $params = [$startX, $startY, $startZ, $endX, $endY, $endZ, $sender];
                break;
            case "/paste":
                $output = "블럭 붙여넣기를 시작했어요";
                $callback = "pasteBlock";
                $params = [$sender->floor(), $sender];
                break;
            case "/cut":
                if(!isset($this->pos[$sender->getName()]) || \count($this->pos[$sender->getName()]) < 2){
                    $output = "지역을 먼저 설정해주세요";
                    break;
                }
                $block = $this->pos[$sender->getName()];
                $endX = \max($block[0]->x, $block[1]->x);
                $endY = \max($block[0]->y, $block[1]->y);
                $endZ = \max($block[0]->z, $block[1]->z);
                $startX = \min($block[0]->x, $block[1]->x);
                $startY = \min($block[0]->y, $block[1]->y);
                $startZ = \min($block[0]->z, $block[1]->z);
                $output = "블럭 복사를 시작했어요";
                $callback = "cutBlock";
                $params = [$startX, $startY, $startZ, $endX, $endY, $endZ, $sender];
                break;
        }
        if(isset($output)){
            $sender->sendMessage("[WorldEditor]$output");
        }

        if(isset($callback) && isset($params) && \is_array($params)){
            $this->{$callback}(...$params);
        }
        return \true;
    }
}
