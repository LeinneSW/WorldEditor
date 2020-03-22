<?php

declare(strict_types=1);

namespace leinne\worldeditor;

use pocketmine\block\Block;
use pocketmine\block\BlockLegacyIds;
use pocketmine\block\tile\Chest;
use pocketmine\block\tile\Tile;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Item;
use pocketmine\item\ItemFactory;
use pocketmine\math\Math;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use pocketmine\world\Position;

class WorldEditor extends PluginBase implements Listener{

    /** @var WorldEditor */
    private static $instance;

    /** @var Item */
    private $tool;

    /** @var int */
    private $tick = 2, $limit = 200;

    /** @var Position[][] */
    private $pos = [];

    /** @var Block[][] */
    private $copy = [], $undo = [], $redo = [];

    public static function getInstance() : WorldEditor{
        return self::$instance;
    }

    public function onEnable() : void{
        self::$instance = $this;

        $this->saveDefaultConfig();
        $data = $this->getConfig()->getAll();

        if(isset($data["update-tick"]) && is_numeric($data["update-tick"])){
            $this->tick = (int) max($data["update-tick"], 1);
        }

        if(isset($data["limit-block"]) && is_numeric($data["limit-block"])){
            $this->limit = (int) max($data["limit-block"], 1);
        }
        $this->tool = ItemFactory::fromString($data["tool"] ?? "IRON_HOE");

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function canEditBlock(Player $player) : bool{
        $data = $this->pos[$player->getName()] ?? [];
        return count($data) === 2 && $data[0]->world === $data[1]->world;
    }

    public function onPlayerInteractEvent(PlayerInteractEvent $ev) : void{
        $block = $ev->getBlock();
        $player = $ev->getPlayer();
        if($player->hasPermission("worldeditor.command.setpos") && $ev->getItem()->equals($this->tool)){
            $ev->setCancelled();
            if($ev->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK){
                $pos = $this->setPos($player, 0, $block->getPos());
                if($pos !== null){
                    $player->sendMessage("[WorldEditor]Pos1 지점을 선택했어요 ({$pos->x}, {$pos->y}, {$pos->z}, {$pos->world->getFolderName()})");
                }
            }else{
                $pos = $this->setPos($player, 1, $block->getPos());
                if($pos !== null){
                    $player->sendMessage("[WorldEditor]Pos2 지점을 선택했어요 ({$pos->x}, {$pos->y}, {$pos->z}, {$pos->world->getFolderName()})");
                }
            }
        }
    }

    public function getMinPos(Player $player) : Position{
        $data = $this->pos[$player->getName()];
        return new Position(min($data[0]->x, $data[1]->x), min($data[0]->y, $data[1]->y), min($data[0]->z, $data[1]->z), $data[0]->world);
    }

    public function getMaxPos(Player $player) : Position{
        $data = $this->pos[$player->getName()];
        return new Position(max($data[0]->x, $data[1]->x), max($data[0]->y, $data[1]->y), max($data[0]->z, $data[1]->z), $data[0]->world);
    }

    public function setPos(Player $player, int $index, Position $pos) : ?Position{
        if($index > 1 || $index < 0 || !$pos->isValid()){
            return null;
        }

        $floor = $pos->floor();
        return $this->pos[$player->getName()][$index] = new Position($floor->x, $floor->y, $floor->z, $pos->world);
    }

    public function onBlockBreakEvent(BlockBreakEvent $ev) : void{
        $block = $ev->getBlock();
        $player = $ev->getPlayer();
        if($player->hasPermission("worldeditor.command.setpos") && $ev->getItem()->equals($this->tool)){
            $ev->setCancelled();
            $pos = $this->setPos($player, 0, $block->getPos());
            if($pos !== null){
                $player->sendMessage("[WorldEditor]Pos1 지점을 선택했어요 ({$pos->x}, {$pos->y}, {$pos->z}, {$pos->world->getFolderName()})");
            }
            return;
        }
    }

    public function saveUndo(Block $block, ?Position $pos = null) : void{
        if(!$block->getPos()->isValid() && ($pos === null || !$pos->isValid())){
            return;
        }

        if($pos !== null){
            $block->position($pos->world, $pos->x, $pos->y, $pos->z);
        }

        $blockPos = $block->getPos();
        if(!isset($this->undo[$key = "{$blockPos->x}:{$blockPos->y}:{$blockPos->z}:{$blockPos->world->getFolderName()}"])){
            $this->undo[$key] = [];
        }
        $this->undo[$key][] = $block;
    }

    public function saveRedo(Block $block, ?Position $pos = null) : void{
        if(!$block->getPos()->isValid() && ($pos === null || !$pos->isValid())){
            return;
        }

        if($pos !== null){
            $block->position($pos->world, $pos->x, $pos->y, $pos->z);
        }

        $blockPos = $block->getPos();
        $key = "{$blockPos->x}:{$blockPos->y}:{$blockPos->z}:{$blockPos->world->getFolderName()}";
        if(!isset($this->redo[$key])){
            $this->redo[$key] = [];
        }
        $this->redo[$key][] = $block;
    }

    public function saveCopy(Block $block, Vector3 $pos, Player $player) : bool{
        if($block->getId() === BlockLegacyIds::AIR){
            return false;
        }

        if(!isset($this->copy[$player->getName()])){
            $this->copy[$player->getName()] = [];
        }

        $blockPos = $block->getPos();
        $blockPos->x = $pos->x;
        $blockPos->y = $pos->y;
        $blockPos->z = $pos->z;
        $blockPos->world = $player->getWorld();
        $this->copy[$player->getName()][] = $block;
        return true;
    }

    public function set(Block $block, ?Position $pos = null) : void{
        if(!$block->getPos()->isValid() && ($pos === null || !$pos->isValid())){
            return;
        }

        if($pos !== null){
            $block->position($pos->world, $pos->x, $pos->y, $pos->z);
        }

        $world = $block->getPos()->world;
        $tile = $world->getTile($block->getPos());
        if($tile instanceof Chest){
            $tile->unpair();
        }
        if($tile instanceof Tile){
            $tile->close();
        }
        $world->setBlock($block->getPos(), $block, false);
    }

    public function setBlock(Position $spos, Position $epos, Block $block, ?int $x = null, ?int $y = null, ?int $z = null) : void{
        $count = 0;
        $x = $x ?? $spos->x;
        $y = $y ?? $spos->y;
        $z = $z ?? $spos->z;
        while(true){
            if($count < $this->limit){
                $before = $spos->world->getBlockAt($x, $y, $z);
                if($before->getId() !== $block->getId() || $before->getMeta() !== $block->getMeta()){
                    ++$count;
                    $this->saveUndo($before);
                    $this->set($block, $before->getPos());
                }
                if(++$x > $epos->x){
                    $x = $spos->x;
                    if(++$z > $epos->z){
                        $z = $spos->z;
                        if(++$y > $epos->y){
                            break;
                        }
                    }
                }
            }else{
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function(int $unused) use($spos, $epos, $block, $x, $y, $z) : void{
                    $this->setBlock($spos, $epos, $block, $x, $y, $z);
                }), $this->tick);
                break;
            }
        }
    }

    public function replaceBlock(Position $spos, Position $epos, Block $block, Block $target, bool $checkDamage, ?int $x = null, ?int $y = null, ?int $z = null) : void{
        $count = 0;
        $x = $x ?? $spos->x;
        $y = $y ?? $spos->y;
        $z = $z ?? $spos->z;
        while(true){
            if($count < $this->limit){
                $before = $spos->world->getBlockAt($x, $y, $z);
                if($before->getId() === $block->getId() && (!$checkDamage || $before->getMeta() === $block->getMeta())){
                    ++$count;
                    $this->saveUndo($before);
                    $this->set($target, $before->getPos());
                }
                if(++$x > $epos->x){
                    $x = $spos->x;
                    if(++$z > $epos->z){
                        $z = $spos->z;
                        if(++$y > $epos->y){
                            break;
                        }
                    }
                }
            }else{
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function(int $unused) use($spos, $epos, $block, $target, $checkDamage, $x, $y, $z) : void{
                    $this->replaceBlock($spos, $epos, $block, $target, $checkDamage, $x, $y, $z);
                }), $this->tick);
                break;
            }
        }
    }

    public function undoBlock(Position $spos, Position $epos, ?int $x = null, ?int $y = null, ?int $z = null) : void{
        $count = 0;
        $x = $x ?? $spos->x;
        $y = $y ?? $spos->y;
        $z = $z ?? $spos->z;
        while(true){
            if($count < $this->limit){
                $key = "$x:$y:$z:{$spos->world->getFolderName()}";
                if(isset($this->undo[$key])){
                    ++$count;
                    /** @var Block $block */
                    $block = array_pop($this->undo[$key]);
                    $this->saveRedo($spos->world->getBlockAt($x, $y, $z));
                    $this->set($block);
                }
                if(++$x > $epos->x){
                    $x = $spos->x;
                    if(++$z > $epos->z){
                        $z = $spos->z;
                        if(++$y > $epos->y){
                            break;
                        }
                    }
                }
            }else{
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function(int $unused) use($spos, $epos, $x, $y, $z) : void{
                    $this->undoBlock($spos, $epos, $x, $y, $z);
                }), $this->tick);
                break;
            }
        }
    }

    public function redoBlock(Position $spos, Position $epos, ?int $x = null, ?int $y = null, ?int $z = null) : void{
        $count = 0;
        $x = $x ?? $spos->x;
        $y = $y ?? $spos->y;
        $z = $z ?? $spos->z;
        while(true){
            if($count < $this->limit){
                $key = "$x:$y:$z:{$spos->world->getFolderName()}";
                if(isset($this->redo[$key])){
                    ++$count;
                    /** @var Block $block */
                    $block = array_pop($this->redo[$key]);
                    $this->saveUndo($spos->world->getBlockAt($x, $y, $z));
                    $this->set($block);
                }
                if(++$x > $epos->x){
                    $x = $spos->x;
                    if(++$z > $epos->z){
                        $z = $spos->z;
                        if(++$y > $epos->y){
                            break;
                        }
                    }
                }
            }else{
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function(int $unused) use($spos, $epos, $x, $y, $z) : void{
                    $this->redoBlock($spos, $epos, $x, $y, $z);
                }), $this->tick);
                break;
            }
        }
    }

    public function cutBlock(Vector3 $spos, Vector3 $epos, Player $player, ?int $x = null, ?int $y = null, ?int $z = null) : void{
        if($player->isClosed() || !$player->getPosition()->isValid()){
            return;
        }

        $count = 0;
        $x = $x ?? $spos->x;
        $y = $y ?? $spos->y;
        $z = $z ?? $spos->z;

        $air = VanillaBlocks::AIR();
        while(true){
            if($count < $this->limit){
                $block = $player->getWorld()->getBlockAt($x, $y, $z);
                if($block->getId() !== BlockLegacyIds::AIR){
                    ++$count;
                    $this->saveCopy($block, new Position($x - $spos->x, $y - $spos->y, $z - $spos->z, $player->getWorld()), $player);
                    $this->saveUndo($block);
                    $air->position($player->getWorld(), $x, $y, $z);
                    $this->set($air);
                }
                if(++$x > $epos->x){
                    $x = $spos->x;
                    if(++$z > $epos->z){
                        $z = $spos->z;
                        if(++$y > $epos->y){
                            break;
                        }
                    }
                }
            }else{
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function(int $unused) use($spos, $epos, $player, $x, $y, $z) : void{
                    $this->cutBlock($spos, $epos, $player, $x, $y, $z);
                }), $this->tick);
                break;
            }
        }
    }

    public function copyBlock(Vector3 $spos, Vector3 $epos, Player $player) : void{
        if($player->isClosed() || !$player->getPosition()->isValid()){
            return;
        }

        for($x = $spos->x; $x <= $epos->x; ++$x) for($y = $spos->y; $y <= $epos->y; ++$y) for($z = $spos->z; $z <= $epos->z; ++$z){
            $block = $player->getWorld()->getBlockAt($x, $y, $z);
            if($block->getId() !== BlockLegacyIds::AIR){
                $this->saveCopy($player->getWorld()->getBlockAt($x, $y, $z), new Vector3($x - $spos->x, $y - $spos->y, $z - $spos->z), $player);
            }
        }
    }

    /**
     * @param Player $player
     * @param Position|null $pos
     * @param Block[]|null $copy
     */
    public function pasteBlock(Player $player, ?Position $pos = null, ?array $copy = null) : void{
        if($player->isClosed() || !$player->getPosition()->isValid()){
            return;
        }

        if($copy === null && !isset($this->copy[$player->getName()])){
            return;
        }

        if($pos === null){
            $pos = $player->getPosition();
            $pos->x = Math::floorFloat($pos->x);
            $pos->y = (int) floor($pos->y);
            $pos->z = Math::floorFloat($pos->z);
        }

        $copy = $copy ?? $this->copy[$player->getName()];
        $count = 0;
        while(true){
            if($count++ < $this->limit){
                if(($block = array_pop($copy)) !== null){
                    $block = clone $block;
                    $blockPos = $block->getPos();
                    $blockPos->x += $pos->x;
                    $blockPos->y += $pos->y;
                    $blockPos->z += $pos->z;
                    $blockPos->world = $pos->world;
                    $this->saveUndo($pos->world->getBlock($blockPos));
                    $this->set($block);
                }else{
                    break;
                }
            }else{
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function(int $unused) use($player, $pos, $copy) : void{
                    $this->pasteBlock($player, $pos, $copy);
                }), $this->tick);
                break;
            }
        }
    }

    /*public function sphereBlock(Position $pos, Block $block, int $radius, bool $filled) : void{
        $invRadius = 1 / $radius;

        $nextXn = 0;
        $breakX = false;
        for($x = 0; $x <= $radius && !$breakX; ++$x){
            $xn = $nextXn;
            $nextXn = ($x + 1) * $invRadius;
            $nextYn = 0;
            $breakY = false;
            for($y = 0; $y <= $radius && !$breakY; ++$y){
                $yn = $nextYn;
                $nextYn = ($y + 1) * $invRadius;
                $nextZn = 0;
                $breakZ = false;
                for($z = 0; $z <= $radius; ++$z){
                    $zn = $nextZn;
                    $nextXn = ($z + 1) * $invRadius;
                    $distanceSq = ($xn ** 2) + ($yn ** 2) + ($zn ** 2);
                    if($distanceSq > 1){
                        if($z === 0){
                            if($y === 0){
                                $breakX = true;
                                $breakY = true;
                                break;
                            }
                            $breakY = true;
                            break;
                        }
                        break;
                    }

                    if(
                        !$filled
                        && ($nextXn ** 2) + ($yn ** 2) + ($zn ** 2) <= 1
                        && ($xn ** 2) + ($nextYn ** 2) + ($zn ** 2) <= 1
                        && ($xn ** 2) + ($yn ** 2) + ($nextZn ** 2) <= 1
                    ){
                        continue;
                    }

                    $this->set($block, $pos->add($x, $y, $z));
                    $this->set($block, $pos->add(-$x, $y, $z));
                    $this->set($block, $pos->add($x, -$y, $z));
                    $this->set($block, $pos->add($x, $y, -$z));
                    $this->set($block, $pos->add(-$x, -$y, $z));
                    $this->set($block, $pos->add(-$x, $y, -$z));
                    $this->set($block, $pos->add($x, -$y, -$z));
                    $this->set($block, $pos->add(-$x, -$y, -$z));
                }
            }
        }
    }*/

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $sub) : bool{
        if(!($sender instanceof Player)){
            $sender->sendMessage("[WorldEditor]인게임에서 이용 가능해요");
            return true;
        }

        switch($cmd->getName()){
            case "/wand":
                $output = "월드에딧 도구를 제공했어요";
                $sender->getInventory()->setItemInHand($this->tool);
                break;
            case "/pos1":
                $pos = $this->setPos($sender, 0, $sender->getPosition());
                if($pos !== null){
                    $output = "현재 위치를 Pos1 지점으로 지정했어요 ({$pos->x}, {$pos->y}, {$pos->z}, {$pos->world->getFolderName()})";
                }
                break;
            case "/pos2":
                $pos = $this->setPos($sender, 1, $sender->getPosition());
                if($pos !== null){
                    $output = "현재 위치를 Pos2 지점으로 지정했어요 ({$pos->x}, {$pos->y}, {$pos->z}, {$pos->world->getFolderName()})";
                }
                break;
            case "/set":
                if(!isset($sub[0])){
                    $output = "사용법: //set <블럭>";
                    break;
                }
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                try{
                    $output = "블럭을 설정중이에요";
                    $block = ItemFactory::fromString($sub[0])->getBlock();
                    $this->setBlock($this->getMinPos($sender), $this->getMaxPos($sender), $block);
                }catch(\Exception $e){
                    $output = "존재하지 않는 블럭이에요";
                }
                break;
            case "/replace":
                if(count($sub) < 2){
                    $output = "사용법: //replace <선택할 블럭> <바꿀 블럭> [<대미지 체크여부(true|false)>]";
                    break;
                }
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                try{
                    $output = "블럭을 변경하는중이에요";
                    $source = ItemFactory::fromString($sub[0])->getBlock();
                    $target = ItemFactory::fromString($sub[1])->getBlock();
                    $this->replaceBlock($this->getMinPos($sender), $this->getMaxPos($sender), $source, $target, ($sub[2] ?? "") === "true");
                }catch(\Exception $e){
                    $output = "존재하지 않는 블럭이에요";
                }
                break;
            case "/undo":
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $output = "블럭을 되돌리는 중이에요";
                $this->undoBlock($this->getMinPos($sender), $this->getMaxPos($sender));
                break;
            case "/redo":
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $output = "블럭을 되돌리는 중이에요";
                $this->redoBlock($this->getMinPos($sender), $this->getMaxPos($sender));
                break;
            case "/cut":
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $output = "블럭을 잘라내는 중이에요";
                $this->copy[$sender->getName()] = [];
                $this->cutBlock($this->getMinPos($sender), $this->getMaxPos($sender), $sender);
                break;
            case "/copy":
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $output = "블럭을 복사중이에요";
                $this->copy[$sender->getName()] = [];
                $this->copyBlock($this->getMinPos($sender), $this->getMaxPos($sender), $sender);
                break;
            case "/paste":
                $output = "블럭 붙여넣기를 시작했어요";
                $this->pasteBlock($sender);
                break;
        }
        if(isset($output)){
            $sender->sendMessage("[WorldEditor]$output");
        }
        return true;
    }
}
