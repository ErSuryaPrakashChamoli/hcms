<div class="ps-vis">
    <div class="ps-win">
        <div class="ps-win-bar"><i></i><i></i><i></i><span></span></div>
        <div class="ps-win-body">
            <div class="ps-win-rail"><i></i><i class="is-on"></i><i></i><i></i><i></i><i></i></div>
            <div class="ps-win-main">
                <div class="ps-v-greet">Good morning, Asha<small>3 things need you today</small></div>
                <div class="ps-v-tiles">
                    <div class="ps-v-tile"><span>People</span><b>1,284</b><em>+12 this month</em></div>
                    <div class="ps-v-tile"><span>Present today</span><b>96.4%</b><em>On track</em></div>
                    <div class="ps-v-tile"><span>Approvals</span><b>7</b><em style="color:var(--ps-hue-gold)">2 due today</em></div>
                </div>
                <div class="ps-v-row"><span class="ps-v-av" style="--c:#2f5fb3">RK</span><span class="ps-v-grow"><b>Rohan Kapoor · Leave</b><span>2 days · 18–19 Nov</span></span><span class="ps-v-btn">Approve</span></div>
                <div class="ps-v-row"><span class="ps-v-av" style="--c:#94416f">MS</span><span class="ps-v-grow"><b>Meera Shah · Regularise</b><span>Missed punch · 14 Nov</span></span><span class="ps-v-btn">Approve</span></div>
                <div class="ps-v-row"><span class="ps-v-av" style="--c:#0e6f7a">PY</span><span class="ps-v-grow"><b>November payroll</b><span>Review · step 4 of 5</span><span class="ps-v-bar" style="margin-top:4px"><i style="--w:78%"></i></span></span></div>
            </div>
        </div>
    </div>
    @include('website.visuals._float', ['pos' => 'left:-22px; bottom:-22px', 'icon' => 'heroicon-m-check', 'title' => 'Payslips published', 'sub' => '1,284 employees · just now'])
    @include('website.visuals._float', ['pos' => 'right:-16px; top:64px', 'icon' => 'heroicon-m-calendar-days', 'tone' => 'is-gold', 'title' => 'Leave approved', 'sub' => 'Kabir · 3 days', 'late' => true])
</div>
