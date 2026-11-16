<div class="ps-vis">
    <div class="ps-win">
        <div class="ps-win-bar"><i></i><i></i><i></i><span></span></div>
        <div class="ps-win-main" style="padding:18px; gap:10px">
            <div style="display:flex;justify-content:space-between;align-items:center"><div class="ps-v-greet">People<small>1,284 active · 6 locations</small></div><span class="ps-v-btn">Hire</span></div>
            @foreach ([['AS','Asha Srinivasan','Head of People · Bengaluru','#574bc4','Active'],['RK','Rohan Kapoor','Payroll Lead · Pune','#2f5fb3','Active'],['MS','Meera Shah','Engineer II · Remote','#94416f','On leave'],['KM','Kabir Mehta','Plant Supervisor · Chakan','#0e6f7a','Active'],['NI','Nisha Iyer','Joining 2 Dec · Chennai','#8a6418','Pre-joining']] as [$i,$n,$r,$c,$s])
                <div class="ps-v-row"><span class="ps-v-av" style="--c:{{ $c }}">{{ $i }}</span><span class="ps-v-grow"><b>{{ $n }}</b><span>{{ $r }}</span></span><span @class(['ps-v-pill', 'is-gold' => $s === 'On leave', 'is-iris' => $s === 'Pre-joining'])>{{ $s }}</span></div>
            @endforeach
        </div>
    </div>
    @include('website.visuals._float', ['pos' => 'right:-18px; top:40px', 'icon' => 'heroicon-m-clock', 'tone' => 'is-iris', 'title' => 'Effective 1 Dec', 'sub' => 'Promotion · history kept'])
</div>
