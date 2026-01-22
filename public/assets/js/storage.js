export async function initStorage() {
    return await idb.openDB('MeterDB', 1, {
        upgrade(db) {
            if (!db.objectStoreNames.contains('settings')) {
                db.createObjectStore('settings');
            }
        },
    });
}

export async function getConfig() {
    const db = await initStorage();
    return await db.get('settings', 'config');
}

export async function saveConfigToDB(config) {
    const db = await initStorage();
    await db.put('settings', config, 'config');
}

export async function deleteConfig() {  // <--- Das 'export' ist entscheidend!
    const db = await initStorage();
    await db.delete('settings', 'config');
}