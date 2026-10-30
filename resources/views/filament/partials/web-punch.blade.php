{{-- Web check in / out: sends the browser's device id, and its location when the check-in policy needs one. --}}
@once
    <script>
        window.peoplePunch = async (wire, direction, needsLocation) => {
            const client = {};
            try {
                let id = localStorage.getItem('peopleos.device');
                if (! id) { id = 'web-' + (crypto.randomUUID ? crypto.randomUUID() : String(Date.now()) + Math.random().toString(16).slice(2)); localStorage.setItem('peopleos.device', id); }
                client.device_identifier = id;
            } catch (e) {}
            if (needsLocation && navigator.geolocation) {
                await new Promise((resolve) => navigator.geolocation.getCurrentPosition(
                    (p) => { client.latitude = p.coords.latitude; client.longitude = p.coords.longitude; resolve(); },
                    (e) => { client.location_error = e.message || 'unavailable'; resolve(); },
                    { enableHighAccuracy: true, timeout: 10000, maximumAge: 60000 },
                ));
            }
            await wire.punch(direction, client);
        };
    </script>
@endonce
