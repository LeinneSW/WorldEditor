<?php

declare(strict_types=1);

namespace leinne\worldeditor;

use pocketmine\block\Air;
use pocketmine\block\Block;
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
            if($count < $this->getLimit()){
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
                }), 1);
                return;
            }
        }
    }

    public function replaceBlock(Position $spos, Position $epos, Block $block, Block $target, ?int $x = \null, ?int $y = \null, ?int $z = \null) : void{
        $count = 0;
        $x = $x ?? $spos->x;
        $y = $y ?? $spos->y;
        $z = $z ?? $spos->z;
        while(\true){
            if($count < $this->getLimit()){
                $before = $spos->level->getBlockAt($x, $y, $z);
                if($before->getId() === $block->getId() && $before->getDamage() === $block->getDamage()){
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
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($spos, $epos, $block, $target, $x, $y, $z){
                    $this->replaceBlock($spos, $epos, $block, $target, $x, $y, $z);
                }), 1);
                return;
            }
        }
    }

    public function undoBlock(Position $spos, Position $epos, ?int $x = \null, ?int $y = \null, ?int $z = \null) : void{
        $count = 0;
        $x = $x ?? $spos->x;
        $y = $y ?? $spos->y;
        $z = $z ?? $spos->z;
        while(\true){
            if($count < $this->getLimit()){
                $key = "$x:$y:$z:{$spos->level->getFolderName()}";
                if(isset($this->undo[$key])){
                    ++$count;
                    /** @var Block $block */
                    $block = \array_pop($this->undo[$key]);
                    $this->saveRedo($spos->level->getBlockAt($x, $y, $z));
                    $this->set($block);
                    if(\count($this->undo[$key]) === 0){
                        unset($this->undo[$key]);
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
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($spos, $epos, $x, $y, $z){
                    $this->undoBlock($spos, $epos, $x, $y, $z);
                }), 1);
                return;
            }
        }
    }

    public function redoBlock(Position $spos, Position $epos, ?int $x = \null, ?int $y = \null, ?int $z = \null) : void{
        $count = 0;
        $x = $x ?? $spos->x;
        $y = $y ?? $spos->y;
        $z = $z ?? $spos->z;
        while(\true){
            if($count < $this->getLimit()){
                $key = "$x:$y:$z:{$spos->level->getFolderName()}";
                if(isset($this->redo[$key])){
                    ++$count;
                    /** @var Block $block */
                    $block = \array_pop($this->redo[$key]);
                    $this->saveUndo($spos->level->getBlockAt($x, $y, $z));
                    $this->set($block);
                    if(\count($this->redo[$key]) === 0){
                        unset($this->redo[$key]);
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
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($spos, $epos, $x, $y, $z){
                    $this->redoBlock($spos, $epos, $x, $y, $z);
                }), 1);
                return;
            }
        }
    }

    public function cutBlock(Vector3 $spos, Vector3 $epos, Player $player, ?int $x = \null, ?int $y = \null, ?int $z = \null) : void{
        $count = 0;
        $x = $x ?? $spos->x;
        $y = $y ?? $spos->y;
        $z = $z ?? $spos->z;
        while(\true){
            if($count < $this->getLimit()){
                $block = $player->level->getBlockAt($x, $y, $z);
                if($block->getId() !== Block::AIR){
                    ++$count;
                    if(!isset($this->copy[$player->getName()])){
                        $this->copy[$player->getName()] = [];
                    }
                    $this->saveCopy($block, new Position($x - $spos->x, $y - $spos->y, $z - $spos->z, $player->level), $player);
                    $this->saveUndo($block);
                    $this->set(new Air(), $block);
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
                }), 1);
                return;
            }
        }
    }

    public function copyBlock(Vector3 $spos, Vector3 $epos, Player $player) : void{
        for($x = $spos->x; $x <= $epos->x; ++$x) for($y = $spos->y; $y <= $epos->y; ++$y) for($z = $spos->z; $z <= $epos->z; ++$z){
            $block = $player->level->getBlockAt($x, $y, $z);
            if($block->getId() !== Block::AIR){
                $this->saveCopy($player->level->getBlockAt($x, $y, $z), new Vector3($x - $spos->x, $y - $spos->y, $z - $spos->z), $player);
            }
        }
    }

    public function pasteBlock(Vector3 $pos, Player $player) : void{
        $count = 0;
        while(\true){
            if($count < $this->getLimit()){
                if(isset($this->copy[$player->getName()]) && ($block = \array_pop($this->copy[$player->getName()])) !== \null){
                    ++$count;

                    $block->x += $pos->x;
                    $block->y += $pos->y;
                    $block->z += $pos->z;
                    $this->saveUndo($player->level->getBlock($block), $block);
                    $this->set($block);
                }else{
                    break;
                }
            }else{
                $this->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use($pos, $player) {
                    $this->pasteBlock($pos, $player);
                }), 1);
                return;
            }
        }
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $sub) : bool{
        if(!($sender instanceof Player)){
            return \true;
        }

        switch($cmd->getName()){
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
                    $output = "사용법: //set <id[:meta]>";
                    break;
                }
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $data = $this->pos[$sender->getName()];
                $spos = new Position(\min($data[0]->x, $data[1]->x), \min($data[0]->y, $data[1]->y), \min($data[0]->z, $data[1]->z), $data[0]->level);
                $epos = new Position(\max($data[0]->x, $data[1]->x), \max($data[0]->y, $data[1]->y), \max($data[0]->z, $data[1]->z), $data[0]->level);
                $output = "블럭을 설정했어요";
                $this->setBlock($spos, $epos, ItemFactory::fromString($sub[0])->getBlock());
                break;
            case "/replace":
                if(!isset($sub[0]) or !isset($sub[1])){
                    $output = "사용법: //replace <(선택)id[:meta]> <(바꿀)id[:meta>]";
                    break;
                }
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $data = $this->pos[$sender->getName()];
                $spos = new Position(\min($data[0]->x, $data[1]->x), \min($data[0]->y, $data[1]->y), \min($data[0]->z, $data[1]->z), $data[0]->level);
                $epos = new Position(\max($data[0]->x, $data[1]->x), \max($data[0]->y, $data[1]->y), \max($data[0]->z, $data[1]->z), $data[0]->level);
                $output = "블럭을 변경했어요";
                $this->replaceBlock($spos, $epos, ItemFactory::fromString($sub[0])->getBlock(), ItemFactory::fromString($sub[1])->getBlock());
                break;
            case "/undo":
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $data = $this->pos[$sender->getName()];
                $spos = new Position(\min($data[0]->x, $data[1]->x), \min($data[0]->y, $data[1]->y), \min($data[0]->z, $data[1]->z), $data[0]->level);
                $epos = new Position(\max($data[0]->x, $data[1]->x), \max($data[0]->y, $data[1]->y), \max($data[0]->z, $data[1]->z), $data[0]->level);
                $output = "블럭을 되돌리는 중입니다";
                $this->undoBlock($spos, $epos);
                break;
            case "/redo":
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $data = $this->pos[$sender->getName()];
                $spos = new Position(\min($data[0]->x, $data[1]->x), \min($data[0]->y, $data[1]->y), \min($data[0]->z, $data[1]->z), $data[0]->level);
                $epos = new Position(\max($data[0]->x, $data[1]->x), \max($data[0]->y, $data[1]->y), \max($data[0]->z, $data[1]->z), $data[0]->level);
                $output = "블럭 설정을 시작했어요";
                $this->redoBlock($spos, $epos);
                break;
            case "/copy":
                if(!$this->canEditBlock($sender)){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $data = $this->pos[$sender->getName()];
                $spos = new Position(\min($data[0]->x, $data[1]->x), \min($data[0]->y, $data[1]->y), \min($data[0]->z, $data[1]->z), $data[0]->level);
                $epos = new Position(\max($data[0]->x, $data[1]->x), \max($data[0]->y, $data[1]->y), \max($data[0]->z, $data[1]->z), $data[0]->level);
                $output = "블럭 복사를 시작했어요";
                $this->copyBlock($spos, $epos, $sender);
                break;
            case "/paste":
                $output = "블럭 붙여넣기를 시작했어요";
                $this->pasteBlock($sender->floor(), $sender);
                break;
            case "/cut":
                if(!isset($this->pos[$sender->getName()]) || \count($this->pos[$sender->getName()]) < 2){
                    $output = "지역을 올바르게 설정해주세요";
                    break;
                }
                $data = $this->pos[$sender->getName()];
                $spos = new Position(\min($data[0]->x, $data[1]->x), \min($data[0]->y, $data[1]->y), \min($data[0]->z, $data[1]->z), $data[0]->level);
                $epos = new Position(\max($data[0]->x, $data[1]->x), \max($data[0]->y, $data[1]->y), \max($data[0]->z, $data[1]->z), $data[0]->level);
                $output = "블럭 복사를 시작했어요";
                $this->cutBlock($spos, $epos, $sender);
                break;
        }
        if(isset($output)){
            $sender->sendMessage("[WorldEditor]$output");
        }
        return \true;
    }
}
