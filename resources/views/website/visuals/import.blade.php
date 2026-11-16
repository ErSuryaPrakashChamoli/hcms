<div class="ps-vis">
    <div class="ps-win">
        <div class="ps-win-bar"><i></i><i></i><i></i><span></span></div>
        <div class="ps-win-main" style="padding:16px; gap:12px">
            <div style="display:flex;justify-content:space-between;align-items:center"><div class="ps-v-greet">People import<small>employees-nov.xlsx · checked before saving</small></div><span class="ps-v-pill">Ready</span></div>
            <div class="ps-sheet-grid" style="border-left:1px solid var(--ps-line);border-top:1px solid var(--ps-line);border-radius:10px;overflow:hidden">
                <div class="is-h is-n">#</div><div class="is-h">Name</div><div class="is-h">Code</div><div class="is-h">Joining</div><div class="is-h"></div>
                @foreach ([['Asha Srinivasan','EMP0001','01-04-2019',1],['Rohan Kapoor','EMP0002','15-06-2020',1],['Meera Shah','EMP0003','02-01-2022',1],['Kabir Mehta','EMP0004','31-02-2023',0],['Nisha Iyer','EMP0005','02-12-2026',1]] as $n => [$a,$b,$c,$ok])
                    <div class="is-n">{{ $n + 1 }}</div><div>{{ $a }}</div><div>{{ $b }}</div><div @class(['bad' => ! $ok])>{{ $c }}</div><div class="{{ $ok ? 'ok' : 'bad' }}">{{ $ok ? '✓' : '!' }}</div>
                @endforeach
            </div>
            <div class="ps-v-row"><span class="ps-v-grow"><b>1,283 of 1,284 rows ready</b><span>Row 4: 31 February is not a date</span><span class="ps-v-bar" style="margin-top:4px"><i style="--w:99%"></i></span></span><span class="ps-v-btn">Fix &amp; import</span></div>
        </div>
    </div>
</div>
