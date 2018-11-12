<?php

declare(strict_types=1);

namespace leinne\worldeditor;

use pocketmine\block\Block;
use pocketmine\block\BlockFactory;
use pocketmine\item\ItemFactory;
use pocketmine\level\Position;
use pocketmine\Player;
use pocketmine\item\Item;
use pocketmine\math\Vector3;
use pocketmine\event\Listener;
use pocketmine\command\Command;
use pocketmine\scheduler\ClosureTask;
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

    /** @var int */
    private $tick = 2, $limit = 200;

    private $pos = [];

    private $copy = [], $undo = [], $redo = [];

    public static function getInstance() : WorldEditor{
        return self::$instance;
    }

    public function onEnable() : void{
        self::$instance = $this;

        $this->saveDefaultConfig();
        $data = $this->getConfig()->getAll();

        if(isset($data["update-tick"]) && \is_numeric($data["update-tick"])){
            $this->tick = (int) \max($data["update-tick"], 1);
        }

        if(isset($data["limit-block"]) && \is_numeric($data["limit-block"])){
            $this->limit = (int) \max($data["limit-block"], 1);
        }
        $this->tool = ItemFactory::fromString($data["tool"] ?? "IRON_HOE");

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
        $this->getServer()->getLogger()->info(TextFormat::GOLD . "[WorldEditor]플러그인이 활성화 되었습니다");
    }

    public function onDisable() : void{
        $this->getServer()->getLogger()->info(TextFormat::GOLD . "[WorldEditor]플러그인이 비활성화 되었습니다");
    }

    public function canEditBlock(Player $player) : bool{
        $data = $this->pos[$player->getName()] ?? [];
        return \count($data) > 1 && $data[0]->level === $data[1]->level;
    }

    public function onPlayerInteractEvent(PlayerInteractEvent $ev) : void{
        $block = $ev->getBlock();
        $player = $ev->getPlayer();
        if($player->hasPermission("worldeditor.command.setpos") && $ev->getItem()->equals($this->tool)){
            $ev->setCancelled();
            if($ev->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK){
                $this->pos[$player->getName()][0] = $block->asPosition();
                $player->sendMessage("[WorldEditor]Pos1 지점을 선택했어요 ({$block->x}, {$block->y}, {$block->z}, {$block->level->getFolderName()})");
            }else{
                $this->pos[$player->getName()][1] = $block->asPosition();
                $player->sendMessage("[WorldEditor]Pos2 지점을 선택했어요 ({$block->x}, {$block->y}, {$block->z}, {$block->level->getFolderName()})");
            }
        }
    }

    public function onBlockBreakEvent(BlockBreakEvent $ev) : void{
        $block = $ev->getBlock();
        $player = $ev->getPlayer();
        if($player->hasPermission("worldeditor.command.setpos") && $ev->getItem()->equals($this->tool)){
            $ev->setCancelled();
            $this->pos[$player->getName()][0] = $block->asPosition();
            $player->sendMessage("[WorldEditor]Pos1 지점을 선택했어요 ({$block->x}, {$block->y}, {$block->z}, {$block->level->getFolderName()})");
            return;
        }
    }

    public function saveUndo(Block $block, ?Position $pos = \null) : void{
        if(!$block->isValid() && ($pos === \null || !$pos->isValid())){
            return;
        }

        if($pos !== \null){
            $block->x = $pos->x;
            $block->y = $pos->y;
            $block->z = $pos->z;
            $block->level = $pos->level;
        }

        if(!isset($this->undo[$key = "{$block->x}:{$block->y}:{$block->z}:{$block->level->getFolderName()}"])){
            $this->undo[$key] = [];
        }
        $this->undo[$key][] = $block;
    }

    public function saveRedo(Block $block, ?Position $pos = \null) : void{
        if(!$block->isValid() && ($pos === \null || !$pos->isValid())){
            return;
        }

        if($pos !== \null){
            $block->x = $pos->x;
            $block->y = $pos->y;
            $block->z = $pos->z;
            $block->level = $pos->level;
        }

        $key = "{$block->x}:{$block->y}:{$block->z}:{$block->level->getFolderName()}";
        if(!isset($this->redo[$key])){
            $this->redo[$key] = [];
        }
        $this->redo[$key][] = $block;
    }

    public function saveCopy(Block $block, Vector3 $pos, Player $player) : bool{
        if($block->getId() === Block::AIR){
            return \false;
        }

        if(!isset($this->copy[$player->getName()])){
            $this->copy[$player->getName()] = [];
        }

        $block->x = $pos->x;
        $block->y = $pos->y;
        $block->z = $pos->z;
        $this->copy[$player->getName()][] = $block;
        return \true;
    }

    public function set(Block $block, ?Position $pos = \null) : void{
        if(!$block->isValid() && ($pos === \null || !$pos->isValid())){
            return;
        }

        if($pos !== \null){
            $block->x = $pos->x;
            $block->y = $pos->y;
            $block->z = $pos->z;
            $block->level = $pos->level;
        }

        $tile = $block->level->getTile($block);
        if($tile instanceof Chest){
            $tile->unpair();
        }
        if($tile instanceof Tile){
            $tile->close();
        }
        $block->level->setBlock($block, $block, \false);
    }

    public function setBlock(Position $spos, Position $epos, Block $block, ?int $x = \null, ?int $y = \null, ?int $z = \null) : void{
        $count = 0;
        $x = $x ?? $spos->x;
        $y = $y ?? $spos->y;
        $z = $z ?? $spos->z;
        while(\true){
            if($count < $this->limit){
                $before = $spos->level->getBlockAt($x, $y, $z);
                if($before->getId() !== $block->getId() or $before->getDamage() !== $block->getDamage()){
                    ++$count;
                    $this->saveUndo($before);
                    $this->set($block, $before);
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
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($spos, $epos, $block, $x, $y, $z){
                    $this->setBlock($spos, $epos, $block, $x, $y, $z);
                }), $this->tick);
            }
        }
    }

    public function replaceBlock(Position $spos, Position $epos, Block $block, Block $target, bool $checkDamage, ?int $x = \null, ?int $y = \null, ?int $z = \null) : void{
        $count = 0;
        $x = $x ?? $spos->x;
        $y = $y ?? $spos->y;
        $z = $z ?? $spos->z;
        while(\true){
            if($count < $this->limit){
                $before = $spos->level->getBlockAt($x, $y, $z);
                if($before->getId() === $block->getId() && (!$checkDamage || $before->getDamage() === $block->getDamage())){
                    ++$count;
                    $this->saveUndo($before);
                    $this->set($target, $before);
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
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($spos, $epos, $block, $target, $checkDamage, $x, $y, $z){
                    $this->replaceBlock($spos, $epos, $block, $target, $checkDamage, $x, $y, $z);
                }), $this->tick);
            }
        }
    }

    public function undoBlock(Position $spos, Position $epos, ?int $x = \null, ?int $y = \null, ?int $z = \null) : void{
        $count = 0;
        $x = $x ?? $spos->x;
        $y = $y ?? $spos->y;
        $z = $z ?? $spos->z;
        while(\true){
            if($count < $this->limit){
                $key = "$x:$y:$z:{$spos->level->getFolderName()}";
                if(isset($this->undo[$key])){
                    ++$count;
                    /** @var Block $block */
                    $block = \array_pop($this->undo[$key]);
                    $this->saveRedo($spos->level->getBlockAt($x, $y, $z));
                    $this->set($block);
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
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($spos, $epos, $x, $y, $z){
                    $this->undoBlock($spos, $epos, $x, $y, $z);
                }), $this->tick);
            }
        }
    }

    public function redoBlock(Position $spos, Position $epos, ?int $x = \null, ?int $y = \null, ?int $z = \null) : void{
        $count = 0;
        $x = $x ?? $spos->x;
        $y = $y ?? $spos->y;
        $z = $z ?? $spos->z;
        while(\true){
            if($count < $this->limit){
                $key = "$x:$y:$z:{$spos->level->getFolderName()}";
                if(isset($this->redo[$key])){
                    ++$count;
                    /** @var Block $block */
                    $block = \array_pop($this->redo[$key]);
                    $this->saveUndo($spos->level->getBlockAt($x, $y, $z));
                    $this->set($block);
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
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($spos, $epos, $x, $y, $z){
                    $this->redoBlock($spos, $epos, $x, $y, $z);
                }), $this->tick);
            }
        }
    }

    public function cutBlock(Vector3 $spos, Vector3 $epos, Player $player, ?int $x = \null, ?int $y = \null, ?int $z = \null) : void{
        if($player->isClosed() || !$player->isValid()){
            return;
        }

        $count = 0;
        $x = $x ?? $spos->x;
        $y = $y ?? $spos->y;
        $z = $z ?? $spos->z;

        $air = BlockFactory::get(Block::AIR);
        $air->level = $player->level;
        while(\true){
            if($count < $this->limit){
                $block = $player->level->getBlockAt($x, $y, $z);
                if($block->getId() !== Block::AIR){
                    ++$count;
                    $this->saveCopy($block, new Position($x - $spos->x, $y - $spos->y, $z - $spos->z, $player->level), $player);
                    $this->saveUndo($block);
                    $this->set($air->setComponents($x, $y, $z));
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
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($spos, $epos, $player, $x, $y, $z){
                    $this->cutBlock($spos, $epos, $player, $x, $y, $z);
                }), $this->tick);
            }
        }
    }

    public function copyBlock(Vector3 $spos, Vector3 $epos, Player $player) : void{
        if($player->isClosed() || !$player->isValid()){
            return;
        }

        for($x = $spos->x; $x <= $epos->x; ++$x) for($y = $spos->y; $y <= $epos->y; ++$y) for($z = $spos->z; $z <= $epos->z; ++$z){
            $block = $player->level->getBlockAt($x, $y, $z);
            if($block->getId() !== Block::AIR){
                $this->saveCopy($player->level->getBlockAt($x, $y, $z), new Vector3($x - $spos->x, $y - $spos->y, $z - $spos->z), $player);
            }
        }
    }

    public function pasteBlock(Player $player, ?array $copy = \null) : void{
        if($player->isClosed() || !$player->isValid()){
            return;
        }

        if($copy === \null && !isset($this->copy[$player->getName()])){
            return;
        }

        $copy = $copy ?? $this->copy[$player->getName()];
        $count = 0;
        while(\true){
            if($count++ < $this->limit){
                if(($block = \array_pop($copy)) !== \null){
                    $block = clone $block;
                    $block->x += (int) $player->x;
                    $block->y += (int) $player->y;
                    $block->z += (int) $player->z;
                    $this->saveUndo($player->level->getBlock($block), $block);
                    $this->set($block);
                }else{
                    break;
                }
            }else{
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($player, $copy){
                    $this->pasteBlock($player, $copy);
                }), $this->tick);
            }
        }
    }

    /*public function sphereBlock(Position $pos, Block $block, int $radius, bool $filled) : void{
        $invRadius = 1 / $radius;

        $nextXn = 0;
        $breakX = \false;
        for($x = 0; $x <= $radius && !$breakX; ++$x){
            $xn = $nextXn;
            $nextXn = ($x + 1) * $invRadius;
            $nextYn = 0;
            $breakY = \false;
            for($y = 0; $y <= $radius && !$breakY; ++$y){
                $yn = $nextYn;
                $nextYn = ($y + 1) * $invRadius;
                $nextZn = 0;
                $breakZ = \false;
                for($z = 0; $z <= $radius; ++$z){
                    $zn = $nextZn;
                    $nextXn = ($z + 1) * $invRadius;
                    $distanceSq = ($xn ** 2) + ($yn ** 2) + ($zn ** 2);
                    if($distanceSq > 1){
                        if($z === 0){
                            if($y === 0){
                                $breakX = \true;
                                $breakY = \true;
                                break;
                            }
                            $breakY = \true;
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
            return \true;
        }

        switch($cmd->getName()){
            case "/wand":
                $output = "월드에딧 도구를 제공했어요";
                $sender->getInventory()->setItemInHand($this->tool);
                break;
            case "/pos1":
                $pos = new Position((int) $sender->x, (int) $sender->y, (int) $sender->z, $sender->level);
                $this->pos[$sender->getName()][0] = $pos;
                $output = "현재 위치를 Pos1 지점으로 지정했어요 ({$pos->x}, {$pos->y}, {$pos->z}, {$pos->level->getFolderName()})";
                break;
            case "/pos2":
                $pos = new Position((int) $sender->x, (int) $sender->y, (int) $sender->z, $sender->level);
                $this->pos[$sender->getName()][1] = $pos;
                $output = "현재 위치를 Pos2 지점으로 지정했어요 ({$pos->x}, {$pos->y}, {$pos->z}, {$pos->level->getFolderName()})";
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
                $data = $this->pos[$sender->getName()];
                $output = "블럭을 설정중이에요";
                $this->setBlock(
                    new Position(\min($data[0]->x, $data[1]->x), \min($data[0]->y, $data[1]->y), \min($data[0]->z, $data[1]->z), $data[0]->level),
                    new Position(\max($data[0]->x, $data[1]->x), \max($data[0]->y, $data[1]->y), \max($data[0]->z, $data[1]->z), $data[0]->level),
                    ItemFactory::fromString($sub[0])->getBlock()
                );
                break;
            case "/replace":
                if(\count($sub) < 2){
                    $output = "사용법: //replace <선택할 블럭> <바꿀 블럭> [<대미지 체크>]";
                    break;
                }
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $data = $this->pos[$sender->getName()];
                $output = "블럭을 변경하는중이에요";
                $this->replaceBlock(
                    new Position(\min($data[0]->x, $data[1]->x), \min($data[0]->y, $data[1]->y), \min($data[0]->z, $data[1]->z), $data[0]->level),
                    new Position(\max($data[0]->x, $data[1]->x), \max($data[0]->y, $data[1]->y), \max($data[0]->z, $data[1]->z), $data[0]->level),
                    ItemFactory::fromString($sub[0])->getBlock(),
                    ItemFactory::fromString($sub[1])->getBlock(),
                    isset($sub[2]) ? $sub[2] === "true" : \true
                );
                break;
            case "/undo":
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $data = $this->pos[$sender->getName()];
                $output = "블럭을 되돌리는 중이에요";
                $this->undoBlock(
                    new Position(\min($data[0]->x, $data[1]->x), \min($data[0]->y, $data[1]->y), \min($data[0]->z, $data[1]->z), $data[0]->level),
                    new Position(\max($data[0]->x, $data[1]->x), \max($data[0]->y, $data[1]->y), \max($data[0]->z, $data[1]->z), $data[0]->level)
                );
                break;
            case "/redo":
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $data = $this->pos[$sender->getName()];
                $output = "블럭을 되돌리는 중이에요";
                $this->redoBlock(
                    new Position(\min($data[0]->x, $data[1]->x), \min($data[0]->y, $data[1]->y), \min($data[0]->z, $data[1]->z), $data[0]->level),
                    new Position(\max($data[0]->x, $data[1]->x), \max($data[0]->y, $data[1]->y), \max($data[0]->z, $data[1]->z), $data[0]->level)
                );
                break;
            case "/cut":
                if(!isset($this->pos[$sender->getName()]) || \count($this->pos[$sender->getName()]) < 2){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $data = $this->pos[$sender->getName()];
                $output = "블럭을 잘라내는 중이에요";
                $this->cutBlock(
                    new Position(\min($data[0]->x, $data[1]->x), \min($data[0]->y, $data[1]->y), \min($data[0]->z, $data[1]->z), $data[0]->level),
                    new Position(\max($data[0]->x, $data[1]->x), \max($data[0]->y, $data[1]->y), \max($data[0]->z, $data[1]->z), $data[0]->level),
                    $sender
                );
                break;
            case "/copy":
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $data = $this->pos[$sender->getName()];
                $output = "블럭을 복사중이에요";
                $this->copyBlock(
                    new Position(\min($data[0]->x, $data[1]->x), \min($data[0]->y, $data[1]->y), \min($data[0]->z, $data[1]->z), $data[0]->level),
                    new Position(\max($data[0]->x, $data[1]->x), \max($data[0]->y, $data[1]->y), \max($data[0]->z, $data[1]->z), $data[0]->level),
                    $sender
                );
                break;
            case "/paste":
                $output = "블럭 붙여넣기를 시작했어요";
                $this->pasteBlock($sender);
                break;
        }
        if(isset($output)){
            $sender->sendMessage("[WorldEditor]$output");
        }
        return \true;
    }
}
