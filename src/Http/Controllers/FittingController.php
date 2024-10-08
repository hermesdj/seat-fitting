<?php

namespace Denngarr\Seat\Fitting\Http\Controllers;

use Denngarr\Seat\Fitting\Exceptions\FittingParserBadFormatException;
use Denngarr\Seat\Fitting\Helpers\CalculateConstants;
use Denngarr\Seat\Fitting\Helpers\CalculateEft;
use Denngarr\Seat\Fitting\Helpers\FittingHelper;
use Denngarr\Seat\Fitting\Models\Doctrine;
use Denngarr\Seat\Fitting\Models\Fitting;
use Denngarr\Seat\Fitting\Validation\DoctrineValidation;
use Denngarr\Seat\Fitting\Validation\FittingValidation;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use RecursiveTree\Seat\PricesCore\Exceptions\PriceProviderException;
use Seat\Eveapi\Models\Alliances\Alliance;
use Seat\Eveapi\Models\Character\CharacterAffiliation;
use Seat\Eveapi\Models\Character\CharacterInfo;
use Seat\Eveapi\Models\Corporation\CorporationInfo;
use Seat\Eveapi\Models\Sde\DgmTypeAttribute;
use Seat\Eveapi\Models\Sde\InvType;
use Seat\Services\Exceptions\SettingException;
use Seat\Web\Http\Controllers\Controller;
use Seat\Web\Models\Acl\Role;

class FittingController extends Controller implements CalculateConstants
{
    use CalculateEft;

    private $requiredSkills = [];

    public function getSettings(): View|Application|Factory
    {
        return view("fitting::settings");
    }

    /**
     * @throws SettingException
     */
    public function saveSettings(Request $request): RedirectResponse
    {
        $request->validate([
            'admin_price_provider' => 'required|numeric',
            'show_about_footer' => 'boolean'
        ]);

        setting(["fitting.admin_price_provider", (int)$request->input('admin_price_provider')], true);
        setting(["fitting.show_about_footer", (bool)$request->input('show_about_footer')], true);

        return redirect()->back()->with("success", "Updated settings");
    }

    public function getDoctrineEdit($doctrine_id): array
    {
        $selected = [];
        $unselected = [];
        $doctrine_fits = [];

        $fittings = Fitting::all();
        $doctrine_fittings = Doctrine::find($doctrine_id)->fittings()->get();

        foreach ($doctrine_fittings as $doctrine_fitting) {
            $doctrine_fits[] = $doctrine_fitting->id;
        }

        foreach ($fittings as $fitting) {
            $ship = InvType::where('typeName', $fitting->shiptype)->first();

            $entry = [
                'id' => $fitting->id,
                'shiptype' => $fitting->shiptype,
                'fitname' => $fitting->fitname,
                'typeID' => $ship->typeID,
            ];

            if (in_array($fitting->id, $doctrine_fits)) {
                $selected[] = $entry;
            } else {
                $unselected[] = $entry;
            }
        }

        return [
            $selected,
            $unselected,
            $doctrine_id,
            Doctrine::find($doctrine_id)->name,
        ];
    }

    /**
     * @return array<mixed, array<'id'|'name', mixed>>
     */
    public function getDoctrineList(): array
    {
        $doctrine_names = [];

        $doctrines = Doctrine::all();

        if (count($doctrines) > 0) {

            foreach ($doctrines as $doctrine) {
                $doctrine_names[] = [
                    'id' => $doctrine->id,
                    'name' => $doctrine->name,
                ];
            }
        }

        return $doctrine_names;
    }

    /**
     * @return array<mixed, array<'id'|'name'|'shipImg'|'shipType', mixed>>
     */
    public function getDoctrineById($id): array
    {
        $fitting_list = [];

        $doctrine = Doctrine::find($id);
        $fittings = $doctrine->fittings()->get();

        foreach ($fittings as $fitting) {
            $ship = InvType::where('typeName', $fitting->shiptype)->first();

            $fitting_list[] = [
                'id' => $fitting->id,
                'name' => $fitting->fitname,
                'shipType' => $fitting->shiptype,
                'shipImg' => $ship->typeID,
            ];
        }

        return $fitting_list;
    }

    public function delDoctrineById($id): string
    {
        Doctrine::destroy($id);

        return "Success";
    }

    public function deleteFittingById($id): string
    {
        Fitting::destroy($id);

        return "Success";
    }

    public function getSkillsByFitId($id): string
    {
        $characters = [];
        $skillsToons = [];

        $fitting = Fitting::find($id);
        $skillsToons['skills'] = $this->calculate($fitting->eftfitting);
        $skilledCharacters = CharacterInfo::with('skills')->whereIn('character_id', auth()->user()->associatedCharacterIds())->get();

        foreach ($skilledCharacters as $character) {

            $index = $character->character_id;

            $skillsToons['characters'][$index]['id'] = $character->character_id;
            $skillsToons['characters'][$index]['name'] = $character->name;

            foreach ($character->skills as $skill) {

                $rank = DgmTypeAttribute::where('typeID', $skill->skill_id)->where('attributeID', '275')->first();

                $skillsToons['characters'][$index]['skill'][$skill->skill_id]['level'] = $skill->trained_skill_level;
                $skillsToons['characters'][$index]['skill'][$skill->skill_id]['rank'] = $rank->valueFloat;
            }

            // Fill in missing skills so Javascript doesn't barf and you have the correct rank
            foreach ($skillsToons['skills'] as $skill) {

                if (isset($skillsToons['characters'][$index]['skill'][$skill['typeId']])) {
                    continue;
                }

                $rank = DgmTypeAttribute::where('typeID', $skill['typeId'])->where('attributeID', '275')->first();

                $skillsToons['characters'][$index]['skill'][$skill['typeId']]['level'] = 0;
                $skillsToons['characters'][$index]['skill'][$skill['typeId']]['rank'] = $rank->valueFloat;
            }
        }

        return json_encode($skillsToons, JSON_THROW_ON_ERROR);
    }

    protected function getFittings(): Collection
    {
        return Fitting::all();
    }

    /**
     * @return array
     */
    public function getFittingList(): array
    {
        $fitnames = [];
        $alliance_corps = [];

        $fittings = $this->getFittings();

        if ((is_countable($fittings) ? count($fittings) : 0) <= 0)
            return $fitnames;

        foreach ($fittings as $fit) {
            $ship = InvType::where('typeName', $fit->shiptype)->first();

            $fitnames[] = [
                'id' => $fit->id,
                'shiptype' => $fit->shiptype,
                'fitname' => $fit->fitname,
                'typeID' => $ship->typeID
            ];
        }

        return $fitnames;
    }

    public function getEftFittingById($id)
    {
        $fitting = Fitting::find($id);

        return $fitting->eftfitting;
    }

    /**
     * @throws SettingException
     * @throws FittingParserBadFormatException
     * @throws PriceProviderException
     * @throws \JsonException
     */
    public function getFittingCostById($id): JsonResponse
    {
        $fit = Fitting::find($id);

        $items = FittingHelper::parseEveFittingData($fit->eftfitting);

        return response()->json(FittingHelper::toFittingEvaluation($items));
    }

    public function getFittingById($id): JsonResponse
    {
        $fitting = Fitting::find($id);

        $response = $this->fittingParser($fitting->eftfitting);

        $response["exportLinks"] = collect(config("fitting.exportlinks"))->map(fn($link): array => [
            "name" => $link["name"],
            "url" => isset($link["url"]) ? $link["url"] . "?id=$fitting->id" : route($link["route"], ["id" => $fitting->id])
        ])->values();

        return response()->json($response);
    }

    public function getFittingView(): View|Application|Factory
    {
        $corps = [];
        $fitlist = $this->getFittingList();

        if (Gate::allows('global.superuser')) {
            $corpnames = CorporationInfo::all();
        } else {
            $corpids = CharacterAffiliation::whereIn('character_id', auth()->user()->associatedCharacterIds())->select('corporation_id')->get()->toArray();
            $corpnames = CorporationInfo::whereIn('corporation_id', $corpids)->get();
        }

        foreach ($corpnames as $corp) {
            $corps[$corp->corporation_id] = $corp->name;
        }

        return view('fitting::fitting', ['fitlist' => $fitlist, 'corps' => $corps]);
    }

    public function getDoctrineView($id = null)
    {
        $doctrine_list = $this->getDoctrineList();

        return view('fitting::doctrine', [
            'doctrine_list' => $doctrine_list,
            'doctrine_id' => $id
        ]);
    }

    public function getAboutView()
    {
        return view('fitting::about');
    }

    public function saveFitting(FittingValidation $request)
    {
        $fitting = new Fitting();

        if ($request->fitSelection > 0) {
            $fitting = Fitting::find($request->fitSelection);
        }

        $eft = explode("\n", (string)$request->eftfitting);
        [$fitting->shiptype, $fitting->fitname] = explode(", ", substr($eft[0], 1, -2));
        $fitting->eftfitting = $request->eftfitting;
        $fitting->save();

        $fitlist = $this->getFittingList();

        return view('fitting::fitting', ['fitlist' => $fitlist]);
    }

    public function postFitting(FittingValidation $request)
    {
        $eft = $request->input('eftfitting');

        return response()->json($this->fittingParser($eft));
    }


    private function fittingParser($eft)
    {
        $jsfit = [];
        $data = preg_split("/\r?\n\r?\n/", (string)$eft);
        $jsfit['eft'] = $eft;

        $header = preg_split("/\r?\n/", $data[0]);

        [$jsfit['shipname'], $jsfit['fitname']] = explode(",", substr($header[0], 1, -1));
        array_shift($header);
        $data[0] = implode("\r\n", $header);

        // Deal with a blank line between the name and the first low slot    
        $lowslot = array_filter(preg_split("/\r?\n/", $data[0]));
        if ($lowslot === []) {
            $data = array_splice($data, 1, count($data));
        }

        $lowslot = array_filter(preg_split("/\r?\n/", $data[0]));
        $midslot = array_filter(preg_split("/\r?\n/", $data[1]));
        $highslot = array_filter(preg_split("/\r?\n/", $data[2]));
        $rigs = array_filter(preg_split("/\r?\n/", $data[3]));

        // init drones array
        if (count($data) > 4) {
            //Deal with extra blank line between rigs and drones
            $drones = array_filter(preg_split("/\r?\n/", $data[4]));
            if ($drones === []) {
                $data = array_splice($data, 1, count($data));
                $drones = array_filter(preg_split("/\r?\n/", $data[4]));
            }
        }

        // special case for tech 3 cruiser which may have sub-modules
        if (in_array($jsfit['shipname'], ['Tengu', 'Loki', 'Legion', 'Proteus'])) {

            $subslot = array_filter(preg_split("/\r?\n/", $data[4]));

            // bump drones to index 5
            $drones = [];
            if (count($data) > 5) {
                $drones = array_filter(preg_split("/\r?\n/", $data[5]));
            }
        }

        $this->loadSlot($jsfit, "LoSlot", $lowslot);
        $this->loadSlot($jsfit, "MedSlot", $midslot);
        $this->loadSlot($jsfit, "HiSlot", $highslot);

        if (isset($subslot)) {
            $this->loadSlot($jsfit, "SubSlot", $subslot);
        }

        $this->loadSlot($jsfit, "RigSlot", $rigs);

        if (isset($drones)) {
            foreach ($drones as $slot) {
                [$drone, $qty] = explode(" x", $slot);
                $item = InvType::where('typeName', $drone)->first();

                $jsfit['dronebay'][$item->typeID] = [
                    'name' => $drone,
                    'qty' => $qty,
                ];
            }
        }
        return $jsfit;
    }

    private function loadSlot(array &$jsfit, string $slotname, array $slots): void
    {
        $index = 0;

        foreach ($slots as $slot) {
            $module = explode(",", (string)$slot);

            if (!preg_match("/\[Empty .+ slot\]/", $module[0])) {
                $item = InvType::where('typeName', $module[0])->first();

                if (empty($item)) {
                    continue;
                }

                $jsfit[$slotname . $index] = [
                    'id' => $item->typeID,
                    'name' => $module[0],
                ];

                $index++;
            }
        }
        return;
    }


    public function postSkills(FittingValidation $request)
    {
        $skillsToons = [];
        $fitting = $request->input('eftfitting');
        $skillsToons['skills'] = $this->calculate($fitting);

        $characters = $this->getUserCharacters(auth()->user()->id);

        foreach ($characters as $character) {
            $index = $character->characterID;

            $skillsToons['characters'][$index] = [
                'id' => $character->characterID,
                'name' => $character->characterName,
            ];

            //            $characterSkills = $this->getCharacterSkillsInformation($character->characterID);
            $characterSkills = CharacterInfo::with('skills')->where('character_id', $character->characterID)->get();

            foreach ($characterSkills as $skill) {
                $rank = DgmTypeAttributes::where('typeID', $skill->typeID)->where('attributeID', '275')->first();

                $skillsToons['characters'][$index]['skill'][$skill->typeID] = [
                    'level' => $skill->level,
                    'rank' => $rank->valueFloat,
                ];
            }

            // Fill in missing skills so Javascript doesn't barf and you have the correct rank
            foreach ($skillsToons['skills'] as $skill) {

                if (isset($skillsToons['characters'][$index]['skill'][$skill['typeId']])) {
                    continue;
                }

                $rank = DgmTypeAttributes::where('typeID', $skill['typeId'])->where('attributeID', '275')->first();

                $skillsToons['characters'][$index]['skill'][$skill['typeId']] = [
                    'level' => 0,
                    'rank' => $rank->valueFloat,
                ];
            }
        }

        return response()->json($skillsToons);
    }

    /**
     * @return array<mixed, array<'level'|'typeId'|'typeName', mixed>>
     */
    private function getSkillNames($types): array
    {
        $skills = [];

        foreach ($types as $skill_id => $level) {
            $res = InvType::where('typeID', $skill_id)->first();

            $skills[] = [
                'typeId' => $skill_id,
                'typeName' => $res->typeName,
                'level' => $level,
            ];
        }

        ksort($skills);

        return $skills;
    }

    public function getRoleList()
    {
        return Role::all();
    }

    public function saveDoctrine(DoctrineValidation $request)
    {
        $doctrine = new Doctrine();

        if ($request->doctrineid > 0) {
            $doctrine = Doctrine::find($request->doctrineid);
        }

        $doctrine->name = $request->doctrinename;
        $doctrine->save();

        foreach ($request->selectedFits as $fitId) {
            $doctrine->fittings()->sync($request->selectedFits);
        }

        return redirect()->route('fitting.doctrineview');
    }

    public function viewDoctrineReport()
    {
        $doctrines = Doctrine::all();
        $corps = CorporationInfo::all();
        $alliances = [];

        $allids = [];

        foreach ($corps as $corp) {
            if (!is_null($corp->alliance_id)) {
                $allids[] = $corp->alliance_id;
            }
        }

        $alliances = Alliance::whereIn('alliance_id', $allids)->get();

        return view('fitting::doctrinereport', ['doctrines' => $doctrines, 'corps' => $corps, 'alliances' => $alliances]);
    }

    public function runReport($alliance_id, $corp_id, $doctrine_id)
    {
        $characters = collect();

        if ($alliance_id !== '0') {
            $chars = CharacterInfo::with('skills')->whereHas('affiliation', function ($affiliation) use ($alliance_id): void {
                $affiliation->where('alliance_id', $alliance_id);
            })->orderBy('name')->get();
            $characters = $characters->concat($chars);
        } else {
            $characters = CharacterInfo::with('skills')->whereHas('affiliation', function ($affiliation) use ($corp_id): void {
                $affiliation->where('corporation_id', $corp_id);
            })->orderBy('name')->get();
        }


        $doctrine = Doctrine::where('id', $doctrine_id)->first();
        $fittings = $doctrine->fittings;
        $charData = [];
        $fitData = [];
        $data = [];
        $data['fittings'] = [];
        $data['totals'] = [];
        foreach ($characters as $character) {
            $charData[$character->character_id]['name'] = $character->name;
            $charData[$character->character_id]['skills'] = [];

            foreach ($character->skills as $skill) {
                $charData[$character->character_id]['skills'][$skill->skill_id] = $skill->trained_skill_level;
            }
        }

        foreach ($fittings as $fitting) {
            $fit = Fitting::find($fitting->id);

            $data['fittings'][] = $fit->fitname;

            $this->requiredSkills = [];
            $shipSkills = $this->calculate("[" . $fit->shiptype . ", a]");

            foreach ($shipSkills as $shipSkill) {
                $fitData[$fitting->id]['shipskills'][$shipSkill['typeId']] = $shipSkill['level'];
            }

            $this->requiredSkills = [];
            $fitSkills = $this->calculate($fit->eftfitting);
            $fitData[$fitting->id]['name'] = $fit->fitname;

            foreach ($fitSkills as $fitSkill) {
                $fitData[$fitting->id]['skills'][$fitSkill['typeId']] = $fitSkill['level'];
            }
        }

        foreach ($charData as $char) {

            foreach ($fitData as $fit) {
                $canflyfit = true;
                $canflyship = true;

                foreach ($fit['skills'] as $skill_id => $level) {
                    if (isset($char['skills'][$skill_id])) {
                        if ($char['skills'][$skill_id] < $level) {
                            $canflyfit = false;
                        }
                    } else {
                        $canflyfit = false;
                    }
                }

                foreach ($fit['shipskills'] as $skill_id => $level) {
                    if (isset($char['skills'][$skill_id])) {
                        if ($char['skills'][$skill_id] < $level) {
                            $canflyship = false;
                        }
                    } else {
                        $canflyship = false;
                    }
                }

                if (!isset($data['totals'][$fit['name']]['ship'])) {
                    $data['totals'][$fit['name']]['ship'] = 0;
                }
                if (!isset($data['totals'][$fit['name']]['fit'])) {
                    $data['totals'][$fit['name']]['fit'] = 0;
                }

                $data['chars'][$char['name']][$fit['name']]['ship'] = false;
                if ($canflyship) {
                    $data['chars'][$char['name']][$fit['name']]['ship'] = true;
                    $data['totals'][$fit['name']]['ship']++;
                }

                $data['chars'][$char['name']][$fit['name']]['fit'] = false;
                if ($canflyfit) {
                    $data['chars'][$char['name']][$fit['name']]['fit'] = true;
                    $data['totals'][$fit['name']]['fit']++;
                }
            }
        }

        $data['totals']['chars'] = count($charData);

        return response()->json($data);
    }
}
