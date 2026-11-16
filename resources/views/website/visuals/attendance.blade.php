@php($days = ['w','p','p','p','p','p','w','w','p','l','l','p','p','w','w','p','p','h','p','a','w','w','p','p','t','p','p','w','w','p','p'])
<div class="ps-vis">
    <div class="ps-win">
        <div class="ps-win-bar"><i></i><i></i><i></i><span></span></div>
        <div class="ps-win-main" style="padding:18px; gap:14px">
            <div style="display:flex;justify-content:space-between;align-items:center"><div class="ps-v-greet">November<small>Kabir Mehta · General shift 9:30–18:30</small></div><span class="ps-v-pill">21 paid days</span></div>
            <div class="ps-v-cal">
                @foreach (['M','T','W','T','F','S','S'] as $w)<b>{{ $w }}</b>@endforeach
                @foreach ($days as $i => $k)<i class="{{ $k }}">{{ $i + 1 }}</i>@endforeach
            </div>
            <div class="ps-v-legend"><span style="--c:var(--ps-hue-green-bg)">Present</span><span style="--c:var(--ps-hue-gold-bg)">Leave</span><span style="--c:var(--ps-iris-soft)">Holiday</span><span style="--c:#f1f1f6">Week off</span><span style="--c:var(--ps-hue-coral-bg)">Absent</span></div>
        </div>
    </div>
    @include('website.visuals._float', ['pos' => 'left:-20px; top:90px', 'icon' => 'heroicon-m-finger-print', 'title' => 'Checked in · 09:24', 'sub' => 'Head office · on time'])
    @include('website.visuals._float', ['pos' => 'right:-16px; bottom:30px', 'icon' => 'heroicon-m-arrow-path', 'tone' => 'is-iris', 'title' => 'Regularisation approved', 'sub' => '20 Nov · missed punch', 'late' => true])
</div>
