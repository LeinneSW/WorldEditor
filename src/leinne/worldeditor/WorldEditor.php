<?php

declare(strict_types=1);

namespace leinne\worldeditor;

use leinne\worldeditor\task\CopyBlockTask;
use leinne\worldeditor\task\CutBlockTask;
use leinne\worldeditor\task\MakeSphereTask;
use leinne\worldeditor\task\PasteBlockTask;
use leinne\worldeditor\task\RedoBlockTask;
use leinne\worldeditor\task\ReplaceBlockTask;
use leinne\worldeditor\task\SetBlockTask;
use leinne\worldeditor\task\UndoBlockTask;
use pocketmine\block\Block;
use pocketmine\block\BlockLegacyIds;
use pocketmine\block\tile\Tile;
use pocketmine\block\VanillaBlocks;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\item\Item;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\item\VanillaItems;
use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\SingletonTrait;
use pocketmine\utils\TextFormat;
use pocketmine\world\format\Chunk;
use pocketmine\world\Position;

class WorldEditor extends PluginBase implements Listener{
    use SingletonTrait;

    private Item $wand;

    private int $tick = 2, $blockPerTick = 200;

    /** @var SelectedArea[] */
    private array $selectedArea = [];

    /** @var Block[][] */
    public array $copy = [], $undo = [], $redo = [];

    public function onEnable() : void{
        self::$instance = $this;

        $this->saveDefaultConfig();
        $data = $this->getConfig()->getAll();

        $updateTick = $data["update-tick"] ?? null;
        if(is_numeric($updateTick)){
            $this->tick = max((int) $updateTick, 1);
        }

        $blockPerTick = $data["block-per-tick"] ?? $data["limit-block"] ?? null;
        if(is_numeric($blockPerTick)){
            $this->blockPerTick = max((int) $blockPerTick, 1);
        }

        try{
            $this->wand = LegacyStringToItemParser::getInstance()->parse($data["wand"] ?? $data["tool"] ?? "");
        }catch(\Exception $e){
            $this->wand = VanillaItems::WOODEN_AXE();
        }

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    public function getWand() : Item{
        return clone $this->wand;
    }

    public function getUpdateTick() : int{
        return $this->tick;
    }

    public function getBlockPerTick() : int{
        return $this->blockPerTick;
    }

    public function getPosHash(Position $pos) : string{
        return "{$pos->x}:{$pos->y}:{$pos->z}:{$pos->world->getFolderName()}";
    }

    public function getSelectedArea(Player $player) : SelectedArea{
        return $this->selectedArea[spl_object_hash($player)] ??= new SelectedArea();
    }

    public function saveUndo(Block $block, ?Position $pos = null) : void{
        if(!$block->getPos()->isValid() && ($pos === null || !$pos->isValid())){
            return;
        }

        if($pos !== null){
            $block->position($pos->world, $pos->x, $pos->y, $pos->z);
        }
        $this->undo[$this->getPosHash($block->getPos())][] = $block;
    }

    public function saveRedo(Block $block, ?Position $pos = null) : void{
        if(!$block->getPos()->isValid() && ($pos === null || !$pos->isValid())){
            return;
        }

        if($pos !== null){
            $block->position($pos->world, $pos->x, $pos->y, $pos->z);
        }
        $this->redo[$this->getPosHash($block->getPos())][] = $block;
    }

    public function saveCopy(Player $player, Block $block, Vector3 $pos) : bool{
        if($block->getId() === BlockLegacyIds::AIR){
            return false;
        }

        $block->position($player->getWorld(), $pos->x, $pos->y, $pos->z);
        $this->copy[$player->getName()][] = $block;
        return true;
    }

    public function setBlock(Block $block, ?Position $pos = null) : void{
        $pos ??= $block->getPos();
        if($pos === null || !$pos->isValid()){
            return;
        }

        if($pos !== null){
            $block->position($pos->world, $pos->x, $pos->y, $pos->z);
        }

        $tile = $pos->world->getTile($block->getPos());
        if($tile instanceof Tile){
            $tile->close();
        }

        if($pos->world->loadChunk($pos->x >> 4, $pos->z >> 4) === null){
            $pos->world->setChunk($pos->x >> 4, $pos->z >> 4, new Chunk());
        }
        $pos->world->setBlockAt($pos->x, $pos->y, $pos->z, $block, false);
    }

    public function getStringToBlock(string $name) : ?Block{
        try{
            $block = VanillaBlocks::{$name}();
        }catch(\Error $e){
            try{
                $block = LegacyStringToItemParser::getInstance()->parse($name)->getBlock();
            }catch(\InvalidArgumentException $e){
                return null;
            }
        }
        return $block;
    }

    /** @priority MONITOR */
    public function onPlayerInteractEvent(PlayerInteractEvent $ev) : void{
        $item = $ev->getItem();
        $block = $ev->getBlock();
        $player = $ev->getPlayer();
        if($player->hasPermission("worldeditor.command.setpos") && $ev->getItem()->equals($this->wand, false, false)){
            $ev->cancel();
            $selectedArea = $this->getSelectedArea($player);
            $player->sendMessage($ev->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK ?
                $selectedArea->setFirstPosition($block->getPos(), $item) :
                $selectedArea->setSecondPosition($block->getPos(), $item)
            );
        }
    }

    /** @priority MONITOR */
    public function onBlockBreakEvent(BlockBreakEvent $ev) : void{
        $block = $ev->getBlock();
        $player = $ev->getPlayer();
        if($player->hasPermission("worldeditor.command.setpos") && $ev->getItem()->equals($this->wand, false, false)){
            $ev->cancel();
            $player->sendMessage($this->getSelectedArea($player)->setFirstPosition($block->getPos()));
        }
    }

    public function onCommand(CommandSender $sender, Command $cmd, string $label, array $sub) : bool{
        if(!($sender instanceof Player)){
            $sender->sendMessage(TextFormat::RED . "[WorldEditor] 게임 내에서만 사용이 가능합니다");
            return true;
        }

        switch($name = $cmd->getName()){
            case "/wand":
                $sender->getInventory()->setItemInHand($this->wand);
                $sender->sendMessage(TextFormat::AQUA . "[WorldEditor] 월드에딧 도구를 제공했습니다");
                break;
            case "/pos1":
            case "/pos2":
                $pos = $sender->getPosition();
                $selectedArea = $this->getSelectedArea($sender);
                $item = $sender->getInventory()->getItemInHand();
                $sender->sendMessage($name[4] === "1" ?
                    $selectedArea->setFirstPosition($pos, $item) :
                    $selectedArea->setSecondPosition($pos, $item)
                );
                break;
            case "/set":
                if(!isset($sub[0])){
                    $sender->sendMessage("사용법: /set <blockId>");
                    break;
                }
                $selectedArea = $this->getSelectedArea($sender);
                if(!$selectedArea->isValid()){
                    $sender->sendMessage(TextFormat::RED . "[WorldEditor] 먼저 영역을 설정해야 합니다");
                    break;
                }
                $block = $this->getStringToBlock($sub[0]);
                if($block === null){
                    $sender->sendMessage(TextFormat::RED . "[WorldEditor] 존재하지 않는 블럭입니다 (blockId: {$sub[0]})");
                }else{
                    $sender->sendMessage(TextFormat::YELLOW . "[WorldEditor] 블럭 설정을 시작했습니다");
                    $this->getScheduler()->scheduleTask(new SetBlockTask(
                        $selectedArea->getMinPosition(),
                        $selectedArea->getMaxPosition(),
                        $selectedArea->getWorld(),
                        $block,
                        $sender
                    ));
                }
                break;
            case "/replace":
                if(count($sub) < 2){
                    $sender->sendMessage("사용법: //replace <선택할 블럭> <바꿀 블럭> [<meta 체크(true|false)>]");
                    break;
                }
                $selectedArea = $this->getSelectedArea($sender);
                if(!$selectedArea->isValid()){
                    $sender->sendMessage(TextFormat::RED . "[WorldEditor] 먼저 영역을 설정해야 합니다");
                    break;
                }
                $source = $this->getStringToBlock($sub[0]);
                $target = $this->getStringToBlock($sub[1]);
                if($source === null || $target === null){
                    $sender->sendMessage(TextFormat::RED . "[WorldEditor] 존재하지 않는 블럭입니다 (blockId: {$sub[0]})");
                }else{
                    $sender->sendMessage(TextFormat::YELLOW . "[WorldEditor] 블럭 변경을 시작했습니다");
                    $this->getScheduler()->scheduleTask(new ReplaceBlockTask(
                        $selectedArea->getMinPosition(),
                        $selectedArea->getMaxPosition(),
                        $selectedArea->getWorld(),
                        $source,
                        $target,
                        ($sub[2] ?? "") === "true",
                        $sender
                    ));
                }
                break;
            case "/undo":
                $selectedArea = $this->getSelectedArea($sender);
                if(!$selectedArea->isValid()){
                    $sender->sendMessage(TextFormat::RED . "[WorldEditor] 먼저 영역을 설정해야 합니다");
                    break;
                }
                $sender->sendMessage(TextFormat::YELLOW . "[WorldEditor] 블럭을 변경하기 이전으로 되돌리기를 시작했습니다");
                $this->getScheduler()->scheduleTask(new UndoBlockTask(
                    $selectedArea->getMinPosition(),
                    $selectedArea->getMaxPosition(),
                    $selectedArea->getWorld(),
                    $sender
                ));
                break;
            case "/redo":
                $selectedArea = $this->getSelectedArea($sender);
                if(!$selectedArea->isValid()){
                    $sender->sendMessage(TextFormat::RED . "[WorldEditor] 먼저 영역을 설정해야 합니다");
                    break;
                }
                $sender->sendMessage(TextFormat::YELLOW . "[WorldEditor] 변경했던 블럭으로 다시 되돌리기를 시작했습니다");
                $this->getScheduler()->scheduleTask(new RedoBlockTask(
                    $selectedArea->getMinPosition(),
                    $selectedArea->getMaxPosition(),
                    $selectedArea->getWorld(),
                    $sender
                ));
                break;
            case "/cut":
                $selectedArea = $this->getSelectedArea($sender);
                if(!$selectedArea->isValid()){
                    $sender->sendMessage(TextFormat::RED . "[WorldEditor] 먼저 영역을 설정해야 합니다");
                    break;
                }
                $this->copy[$sender->getName()] = [];
                $sender->sendMessage(TextFormat::YELLOW . "[WorldEditor] 블럭 잘라내기를 시작했습니다");
                $this->getScheduler()->scheduleTask(new CutBlockTask(
                    $sender,
                    $selectedArea->getMinPosition(),
                    $selectedArea->getMaxPosition(),
                    $selectedArea->getWorld()
                ));
                break;
            case "/copy":
                $selectedArea = $this->getSelectedArea($sender);
                if(!$selectedArea->isValid()){
                    $sender->sendMessage(TextFormat::RED . "[WorldEditor] 먼저 영역을 설정해야 합니다");
                    break;
                }
                $this->copy[$sender->getName()] = [];
                $sender->sendMessage(TextFormat::YELLOW . "[WorldEditor] 블럭 복사를 시작했습니다");
                $this->getScheduler()->scheduleTask(new CopyBlockTask(
                    $sender,
                    $selectedArea->getMinPosition(),
                    $selectedArea->getMaxPosition(),
                    $selectedArea->getWorld()
                ));
                break;
            case "/paste":
                $selectedArea = $this->getSelectedArea($sender);
                if($selectedArea->getWorld() === null || $selectedArea->getMaxPosition()->y < 0){
                    $sender->sendMessage(TextFormat::RED . "[WorldEditor] 먼저 영역을 설정해야 합니다");
                    break;
                }
                $pos = $selectedArea->getFirstPosition();
                if($pos->y < 0){
                    $pos = $selectedArea->getSecondPosition();
                }
                $sender->sendMessage(TextFormat::YELLOW . "[WorldEditor] 블럭 붙여넣기를 시작했습니다");
                $this->getScheduler()->scheduleTask(new PasteBlockTask($pos, $selectedArea->getWorld(), $this->copy[$sender->getName()], $sender->getHorizontalFacing(), $sender));
                break;
            case "/sphere":
                if(count($sub) < 2){
                    $sender->sendMessage("사용법: //shpere <blockId> <반지름> [구 채우기(true|false)>]");
                    break;
                }
                $selectedArea = $this->getSelectedArea($sender);
                if($selectedArea->getWorld() === null || $selectedArea->getMaxPosition()->y < 0){
                    $sender->sendMessage(TextFormat::RED . "[WorldEditor] 먼저 영역을 설정해야 합니다");
                    break;
                }
                $block = $this->getStringToBlock($sub[0]);
                if($block === null){
                    $sender->sendMessage(TextFormat::RED . "[WorldEditor] 존재하지 않는 블럭입니다 (blockId: {$sub[0]})");
                    break;
                }
                $radius = $sub[1];
                if(!is_numeric($radius)){
                    $sender->sendMessage(TextFormat::RED . "[WorldEditor] 구의 반지름은 숫자여야 합니다");
                    break;
                }
                $pos = $selectedArea->getFirstPosition();
                if($pos->y < 0){
                    $pos = $selectedArea->getSecondPosition();
                }
                $sender->sendMessage(TextFormat::YELLOW . "[WorldEditor] 구 생성을 시작했습니다");
                $this->getScheduler()->scheduleTask(new MakeSphereTask($pos, $selectedArea->getWorld(), $block, (int) $radius, ($sub[2] ?? "") !== "false", $sender));
                break;
        }
        return true;
    }
}
