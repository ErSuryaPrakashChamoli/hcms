<div class="ps-vis">
    <div class="ps-win">
        <div class="ps-win-bar"><i></i><i></i><i></i><span></span></div>
        <div class="ps-win-main" style="padding:18px; gap:14px">
            <div style="display:flex;justify-content:space-between;align-items:center"><div class="ps-v-greet">Headcount and attrition<small>Last 12 months · all companies</small></div><span class="ps-v-pill is-iris">149 reports</span></div>
            <div class="ps-chart">@foreach ([46,52,49,58,61,57,66,70,68,74,79,84] as $h)<i style="--h:{{ $h }}%"></i>@endforeach</div>
            <div style="display:flex;gap:14px;align-items:center">
                <div class="ps-donut"></div>
                <div style="display:grid;gap:6px;flex:1">
                    <div class="ps-v-legend"><span style="--c:#574bc4">Engineering 46%</span><span style="--c:#e2c48c">Operations 26%</span><span style="--c:#2f5fb3">Sales 16%</span></div>
                    <div class="ps-v-tiles" style="grid-template-columns:1fr 1fr"><div class="ps-v-tile"><span>Attrition</span><b>9.2%</b><em>−1.4 pts</em></div><div class="ps-v-tile"><span>Time to hire</span><b>23d</b><em>−4 days</em></div></div>
                </div>
            </div>
        </div>
    </div>
</div>
