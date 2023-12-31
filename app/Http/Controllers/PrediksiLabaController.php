<?php

namespace App\Http\Controllers;

use App\Models\HasilPrediksiLaba;
use App\Models\LabaBulanan;
use Illuminate\Http\Request;
use PhpParser\Node\Expr\Cast\Array_;

class PrediksiLabaController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $charts = LabaBulanan::all();
        $prediksi = HasilPrediksiLaba::all();

        return view('admin.prediksilaba.prediksi',compact('charts', 'prediksi'));
    }

    public function store(Request $request)
    {
        $predictionYear = $request->get('tanggal');

        $data = LabaBulanan::all();

        $numData = $data->count();
        $min = $data->min('laba_bulanan');
        $max = $data->max('laba_bulanan');

        $av = 0;
        $difference = array();
        for ($i=1; $i < $numData; $i++) { 
            $difference[$i] = $data[$i]->laba_bulanan - $data[$i-1]->laba_bulanan;            
            $av = $av + abs($difference[$i]);           
        }
        // dd($difference);
        //av = rata" selisih
        $av = $av / ($numData-1);       
        //B= setengah rata"
        $B = $av / 2;

        $basisinterval = $this->getBase($B);
        // m=Jumlah Interval
        $m = ($max-$min)/$B;
        $jumlahinterval = round($m);
        // I=Panjang Interval
        $I = ($max-$min)/$m;
        $I=round($I);
        // dd($I);
        $u = array();
        // dd($av, $B, $m, $I);
        $startInterval = $min;
        
        $a =0;
        for ($i = 0; $i < $jumlahinterval; $i++) {
            $endInterval = $startInterval + $I;
            $a = $a + 1;
            array_push($u, array('start' => $startInterval, 'end' => $endInterval, 'nilai' => $a));
            $startInterval = $startInterval + $I;
        }

        // fuzzified debt, calculate the fuzzy category of the data
        for ($i=0; $i < $numData; $i++) { 
            $this->getFuzzySet($u, $data[$i]);
        }        

        // make fuzzifikasi
        $fuzzifikasi = array();
        for ($i=0; $i < $numData; $i++) { 
            $tgl = $data[$i]->bulan;
            $laba_bln = $data[$i]->laba_bulanan;
            $x = "0";
            foreach($u as $key => $current)
            {
            if( ($laba_bln - $current['start']) >= $x && ($laba_bln - $current['end']) <= $x )
                {
                    $a = $current['nilai'];
                    array_push($fuzzifikasi, array('bln' => $tgl,'laba_bln' => $laba_bln,'hasil' => $a));     
                }
            }
        }

        $flr = array();
        // make fuzzy logic relationship
        for ($i=0; $i < $numData - 1; $i++) {
            $aj = $fuzzifikasi[$i]['hasil'];
            $ai = $fuzzifikasi[$i+1]['hasil'];
            if (!$this->checkDuplicateRelationship($flr, $ai, $aj)) {
                array_push($flr, array($aj, $ai));              
            }
        }
        
        // make flr group
        $flrg = array();
        foreach ($flr as $key => $value) {
            if (empty($flrg[$value[0]])) {
                $flrg[$value[0]] = array($value[1]);
            } else {
                array_push( $flrg[$value[0]], $value[1]);
            }
        }
        
        $nilai_tengah = array();
        for ($i=0; $i < $numData -1; $i++) { 
            $nt = ($u[$i]['start'] + $u[$i]['end'])/2;
            array_push($nilai_tengah, array('start' => $u[$i]['start'], 'end' => $u[$i]['end'], 'nilai' => $u[$i]['nilai'], 'nt' => $nt ));
        }
        
        $pr = array();
        // for ($i=1; $i <= count($flrg); $i++) { 
        for ($i=1; $i < $numData; $i++) { 
            $pr[$i] = $this->calcPrediction($flrg, $i, $u, $fuzzifikasi[$i]);
        }
        
        // make defuzzifikasi
        $defuzzifikasi = array();
        $af = array();
        $errorPrediction = array();
        $sumMAPE = 0;
        for ($i=0; $i < $numData; $i++) { 
            $tgl = $data[$i]->bulan;
            $laba_bln = $data[$i]->laba_bulanan;
            $hasil = $fuzzifikasi[$i]['hasil'];
            foreach($flrg as $key => $current)
            {
            if( $hasil == $key )
                {
                    $df = $pr[$key];
                    // make APE
                    $af[$i] = $df - $data[$i]->laba_bulanan;
                    $errorPrediction = abs($af[$i]) / $data[$i]->laba_bulanan;
                    array_push($defuzzifikasi, array('bln' => $tgl,'laba_bln' => $laba_bln,'hasil' => $hasil,'df' => $df, 'APE' => $errorPrediction));     
                }
            }
        }
        
        // Make MAPE
        for ($i=1; $i < $numData; $i++) { 
            $sumMAPE = $sumMAPE + $defuzzifikasi[$i]['APE'];
        }
        $MAPE = $sumMAPE/($numData-1);
        
        $i = $numData-1;
        $predictionResult = $this->calcPrediction($flrg, $i, $u, $fuzzifikasi);

        $actualValueOfPredictedData = LabaBulanan::select('laba');

        $laba['bulan'] = $predictionYear;
        $laba['hasil'] = $predictionResult;
        HasilPrediksiLaba::create($laba);


        return view('admin.prediksilaba.hasilprediksi',
            compact('data', 'difference', 'av', 'B', 'u', 'fuzzifikasi', 'flr', 'flrg', 'af', 'errorPrediction', 'MAPE', 
            'predictionYear', 'predictionResult', 'nilai_tengah', 'pr', 'defuzzifikasi', 'MAPE',
            'actualValueOfPredictedData', 'min','endInterval','max','I','m','numData',
        ));
    }

    private function getBase($base) 
    {
        // $initBase = 10000;
        if ($base > 100000 && $base <= 1000000) {
            $initBase = 100000;
        } elseif ($base > 10000 && $base <= 100000) {
            $initBase = 10000;
        } elseif ($base > 1000 && $base <= 10000) {
            $initBase = 1000;
        } elseif ($base > 100 && $base <= 1000) {
            $initBase = 100;
        } elseif ($base > 10 && $base <= 100) {
            $initBase = 10;
        } elseif ($base > 1 && $base <=10) {
            $initBase = 1;
        } elseif ($base > 0 && $base <= 1) {
            $initBase = 0.1;
        }
        // return ceil($base*10 / $initBase) / 10 * $initBase;
        return $initBase;
    }

    private function getFuzzySet($u, $data) 
    {
        foreach ($u as $key => $uItem) {
            if ($data->laba >= $uItem['start'] && $data->laba < $uItem['end']) {
                $data->setUi($key);
            }
        }
    }  
    
    private function checkDuplicateRelationship($flr, $ai, $aj) 
    {
        foreach ($flr as $key => $value) {   
            if ($ai == $value[0] && $aj == $value[0]) {
                return true;
            }        
        }
        return false; 
    }

    private function calcPrediction($flrg, $i, $u, $fuzzifikasi) 
    {
        if (empty($flrg[$i])) {            
            // return ($u[$i]['start'] + $u[$i]['end']) / 2;
            return 0;
        }
        $aj = $flrg[$i];
        $sumOfMidPoint = 0;
        foreach ($aj as $key => $value) {
            
            $midPoint = ($u[$value-1]['start'] + $u[$value-1]['end']) / 2;
            $sumOfMidPoint = $sumOfMidPoint + $midPoint;
        }
        $result = $sumOfMidPoint / count($aj);
        return $result;
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(HasilPrediksiLaba $hasilPrediksiLaba)
    {
        $hasilPrediksiLaba->delete();

        return redirect()->route('prediksilaba.index')->with('success', 'Data Berhasil Dihapus!');
    }
}
