// The showcase personas (fictional people). Each signs in with its own, unelevated permissions.
export const PERSONAS = {
    employee: 'priya.nair@demo.local',
    manager: 'amit.verma@demo.local',
    hr: 'neha.kapoor@demo.local',
    executive: 'meera.iyer@demo.local',
    admin: 'kavya.menon@demo.local',
    payroll: 'arjun.bose@demo.local',
};
export const authFile = (persona) => new URL(`./.auth/${persona}.json`, import.meta.url).pathname;
