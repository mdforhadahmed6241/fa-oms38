<div class="wrap">
    <h1>Order Management Settings</h1>
    
    <h2 class="nav-tab-wrapper">
        <a href="#oms-general-settings" class="nav-tab nav-tab-active">General</a>
        <a href="#oms-courier-settings" class="nav-tab">Couriers</a>
        <a href="#oms-tools" class="nav-tab">Tools</a>
    </h2>

    <div id="oms-general-settings" class="oms-tab-content active">
        <form method="post" action="options.php">
            <?php
            settings_fields('oms_settings_group');
            do_settings_sections('oms-settings');
            submit_button();
            ?>
        </form>
    </div>

    <div id="oms-courier-settings" class="oms-tab-content">
        <div id="courier-list-container">
            <!-- Courier list will be loaded here by JS -->
        </div>

        <div class="oms-card" id="add-courier-card">
            <h2 id="add-courier-heading">Add New Courier</h2>
            <div id="courier-form-fields">
                <input type="hidden" id="courier-id">
                <div class="oms-form-group">
                    <label for="courier-name">Custom Name</label>
                    <input type="text" id="courier-name" placeholder="e.g., Steadfast (My Shop)">
                </div>
                <div class="oms-form-group">
                    <label for="courier-type">Courier Type</label>
                    <select id="courier-type">
                        <option value="">-- Select Type --</option>
                        <option value="steadfast">Steadfast</option>
                        <option value="pathao">Pathao</option>
                    </select>
                </div>
                
                <!-- Steadfast Fields -->
                <div id="steadfast-fields" class="courier-type-fields" style="display: none;">
                    <div class="oms-form-group"><label for="steadfast-api-key">API Key</label><input type="text" id="steadfast-api-key"></div>
                    <div class="oms-form-group"><label for="steadfast-secret-key">Secret Key</label><input type="text" id="steadfast-secret-key"></div>
                    <div class="oms-form-group"><label>Auto-send on 'Ready to Ship'</label><label class="oms-switch"><input type="checkbox" id="steadfast-auto-send" value="yes"><span class="oms-slider round"></span></label></div>
                    <div class="oms-form-group"><label>Webhook URL</label><input type="text" id="steadfast-webhook-url" readonly></div>
                </div>

                <!-- Pathao Fields -->
                <div id="pathao-fields" class="courier-type-fields" style="display: none;">
                    <div class="oms-form-group"><label for="pathao-client-id">Client ID</label><input type="text" id="pathao-client-id"></div>
                    <div class="oms-form-group"><label for="pathao-client-secret">Client Secret</label><input type="text" id="pathao-client-secret"></div>
                    <div class="oms-form-group"><label for="pathao-email">Pathao Email</label><input type="email" id="pathao-email"></div>
                    <div class="oms-form-group"><label for="pathao-password">Pathao Password</label><input type="password" id="pathao-password"></div>
                    <div class="oms-form-group"><label for="pathao-store">Default Store ID</label><input type="text" id="pathao-store"></div>
                    <div class="oms-form-group"><label>Auto-send on 'Ready to Ship'</label><label class="oms-switch"><input type="checkbox" id="pathao-auto-send" value="yes"><span class="oms-slider round"></span></label></div>
                     <div class="oms-form-group"><label>Webhook URL</label><input type="text" id="pathao-webhook-url" readonly></div>
                </div>
            </div>
            <button class="button button-primary" id="save-courier-btn">Save Courier</button>
            <button class="button button-secondary" id="cancel-edit-btn" style="display: none;">Cancel Edit</button>
            <span id="courier-save-spinner" class="spinner"></span>
        </div>
        <div id="oms-courier-save-response" class="oms-response-message" style="display:none; margin-top: 15px;"></div>
    </div>
    
    <div id="oms-tools" class="oms-tab-content">
        <div class="oms-card">
            <h2>Pathao Data Sync</h2>
            <p>If Pathao has added new delivery locations, run this tool to download the latest cities, zones, and areas to your local database. This makes the order details page load much faster.</p>
            <div class="oms-form-group">
                <label for="oms-pathao-sync-courier">Select Pathao Account to Sync With</label>
                <select id="oms-pathao-sync-courier">
                    <option value="">-- Select a Pathao Courier --</option>
                    <?php
                    // **FIXED**: Used new OMS_Helpers class to get couriers
                    $all_couriers = OMS_Helpers::get_couriers();
                    foreach ($all_couriers as $c) {
                        if ($c['type'] === 'pathao') {
                            echo '<option value="' . esc_attr($c['id']) . '">' . esc_html($c['name']) . '</option>';
                        }
                    }
                    ?>
                </select>
            </div>
            <button class="button button-secondary" id="oms-sync-pathao-locations">Sync Pathao Locations Now</button>
            <div id="oms-sync-status">Status: Idle</div>
            <div id="oms-sync-progress-bar-container"><div id="oms-sync-progress-bar"></div></div>
        </div>

        <div class="oms-card">
            <h2>Clear Plugin Cache</h2>
            <p>If you've updated your API key or courier history seems outdated, click here to clear all cached data from this plugin (Pathao tokens, courier success rates, etc.).</p>
            <button class="button button-secondary" id="oms-clear-cache-btn">Clear Plugin Cache Now</button>
            <div id="oms-cache-status">Status: Idle</div>
        </div>
    </div>

    <script type="text/template" id="courier-item-template">
        <div class="oms-card courier-item" data-id="{{id}}">
            <div class="courier-item-details">
                <h3>{{name}} <span class="courier-type-badge type-{{type}}">{{type}}</span></h3>
            </div>
            <div class="courier-item-actions">
                <button class="button button-secondary edit-courier-btn">Edit</button>
                <button class="button button-link-delete delete-courier-btn">Delete</button>
            </div>
        </div>
    </script>
</div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // --- Setup ---
    const ajaxUrl = "<?php echo admin_url('admin-ajax.php'); ?>";
    const settingsNonce = "<?php echo wp_create_nonce('oms_settings_nonce'); ?>";
    // **FIXED**: Used new OMS_Helpers class to get couriers for JS
    let couriers = <?php echo json_encode(OMS_Helpers::get_couriers()); ?>;

    // --- Tab Functionality ---
    const tabs = document.querySelectorAll('.nav-tab-wrapper .nav-tab');
    const tabContents = document.querySelectorAll('.oms-tab-content');
    tabs.forEach(tab => {
        tab.addEventListener('click', e => {
            e.preventDefault();
            tabs.forEach(t => t.classList.remove('nav-tab-active'));
            tab.classList.add('nav-tab-active');
            const target = tab.getAttribute('href');
            tabContents.forEach(content => {
                content.style.display = content.id === target.substring(1) ? 'block' : 'none';
            });
        });
    });
    // Trigger click on the active tab to set initial state
    const activeTab = document.querySelector('.nav-tab-active');
    if (activeTab) {
        activeTab.click();
    }


    // --- Courier Management UI ---
    const courierListContainer = document.getElementById('courier-list-container');
    const courierItemTemplate = document.getElementById('courier-item-template').innerHTML;
    const saveCourierBtn = document.getElementById('save-courier-btn');
    const courierTypeSelect = document.getElementById('courier-type');
    const courierIdField = document.getElementById('courier-id');
    const courierNameField = document.getElementById('courier-name');
    const addCourierHeading = document.getElementById('add-courier-heading');
    const cancelEditBtn = document.getElementById('cancel-edit-btn');
    const responseEl = document.getElementById('oms-courier-save-response');

    function renderCourierList() {
        courierListContainer.innerHTML = '';
        if (couriers.length > 0) {
            couriers.forEach(c => {
                const html = courierItemTemplate.replace(/{{id}}/g, c.id)
                                                .replace(/{{name}}/g, c.name)
                                                .replace(/{{type}}/g, c.type);
                courierListContainer.innerHTML += html;
            });
        } else {
            courierListContainer.innerHTML = '<p>No couriers configured yet. Add one below.</p>';
        }
    }

    function resetCourierForm() {
        courierIdField.value = '';
        courierNameField.value = '';
        courierTypeSelect.value = '';
        courierTypeSelect.disabled = false;
        document.querySelectorAll('#add-courier-card input[type="text"], #add-courier-card input[type="email"], #add-courier-card input[type="password"]').forEach(i => i.value = '');
        document.querySelectorAll('#add-courier-card input[type="checkbox"]').forEach(c => c.checked = false);
        document.querySelectorAll('.courier-type-fields').forEach(f => f.style.display = 'none');
        addCourierHeading.textContent = 'Add New Courier';
        cancelEditBtn.style.display = 'none';
        saveCourierBtn.textContent = 'Save Courier';
    }

    function populateFormForEdit(courierId) {
        const courier = couriers.find(c => c.id === courierId);
        if (!courier) return;

        resetCourierForm();
        addCourierHeading.textContent = `Editing: ${courier.name}`;
        cancelEditBtn.style.display = 'inline-block';
        saveCourierBtn.textContent = 'Update Courier';

        courierIdField.value = courier.id;
        courierNameField.value = courier.name;
        courierTypeSelect.value = courier.type;
        courierTypeSelect.disabled = true;
        
        const fieldsDiv = document.getElementById(`${courier.type}-fields`);
        if (fieldsDiv) {
            fieldsDiv.style.display = 'block';
            for (const [key, value] of Object.entries(courier.credentials)) {
                const input = document.getElementById(`${courier.type}-${key.replace(/_/g, '-')}`);
                if (input) {
                    if (input.type === 'checkbox') {
                        input.checked = value === 'yes';
                    } else {
                        input.value = value;
                    }
                }
            }
        }
        document.getElementById(`${courier.type}-webhook-url`).value = `<?php echo esc_url(get_rest_url(null, 'oms/v1/webhook/')); ?>${courier.id}`;
        window.scrollTo({ top: document.getElementById('add-courier-card').offsetTop, behavior: 'smooth' });
    }

    courierTypeSelect.addEventListener('change', function() {
        document.querySelectorAll('.courier-type-fields').forEach(f => f.style.display = 'none');
        if (this.value) {
            document.getElementById(`${this.value}-fields`).style.display = 'block';
        }
    });

    saveCourierBtn.addEventListener('click', function() {
        const id = courierIdField.value || `${courierTypeSelect.value}_${Date.now()}`;
        const name = courierNameField.value.trim();
        const type = courierTypeSelect.value;
        if (!name || !type) { alert('Please provide a name and select a courier type.'); return; }

        const credentials = {};
        document.querySelectorAll(`#${type}-fields input, #${type}-fields select`).forEach(input => {
            const key = input.id.replace(`${type}-`, '').replace(/-/g, '_');
            credentials[key] = input.type === 'checkbox' ? (input.checked ? 'yes' : 'no') : input.value;
        });

        const newCourier = { id, name, type, credentials };

        const existingIndex = couriers.findIndex(c => c.id === id);
        if (existingIndex > -1) {
            couriers[existingIndex] = newCourier;
        } else {
            couriers.push(newCourier);
        }

        this.disabled = true;
        document.getElementById('courier-save-spinner').style.visibility = 'visible';

        fetch(ajaxUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-form-urlencoded' },
            body: new URLSearchParams({ action: 'oms_save_couriers', nonce: settingsNonce, couriers: JSON.stringify(couriers) })
        })
        .then(res => res.json())
        .then(result => {
            responseEl.textContent = result.data.message;
            responseEl.className = `oms-response-message ${result.success ? 'success' : 'error'}`;
            responseEl.style.display = 'block';
            if (result.success) {
                renderCourierList();
                resetCourierForm();
                setTimeout(() => window.location.reload(), 1000); // Reload to update default courier dropdown
            }
        })
        .finally(() => {
            this.disabled = false;
            document.getElementById('courier-save-spinner').style.visibility = 'hidden';
        });
    });
    
    cancelEditBtn.addEventListener('click', resetCourierForm);

    courierListContainer.addEventListener('click', function(e) {
        const target = e.target;
        if (target.classList.contains('edit-courier-btn')) {
            const courierId = target.closest('.courier-item').dataset.id;
            populateFormForEdit(courierId);
        }
        if (target.classList.contains('delete-courier-btn')) {
            if (!confirm('Are you sure you want to delete this courier?')) return;
            const courierId = target.closest('.courier-item').dataset.id;
            couriers = couriers.filter(c => c.id !== courierId);
            
            fetch(ajaxUrl, {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-form-urlencoded' },
                body: new URLSearchParams({ action: 'oms_save_couriers', nonce: settingsNonce, couriers: JSON.stringify(couriers) })
            }).then(() => setTimeout(() => window.location.reload(), 500));
        }
    });

    renderCourierList();

    // --- Tools Logic ---
    const syncButton = document.getElementById('oms-sync-pathao-locations');
    const syncStatus = document.getElementById('oms-sync-status');
    const progressBarContainer = document.getElementById('oms-sync-progress-bar-container');
    const progressBar = document.getElementById('oms-sync-progress-bar');
    const syncNonce = "<?php echo wp_create_nonce('oms_sync_nonce'); ?>";
    const pathaoSyncSelect = document.getElementById('oms-pathao-sync-courier');

    if (syncButton) {
        syncButton.addEventListener('click', async function() {
            const courierId = pathaoSyncSelect.value;
            if (!courierId) {
                syncStatus.textContent = 'Error: Please select a Pathao account to sync with.';
                return;
            }

            this.disabled = true;
            syncStatus.textContent = 'Starting sync...';
            progressBarContainer.style.display = 'block';
            progressBar.style.width = '0%';

            try {
                const cityResponse = await fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'oms_sync_cities', nonce: syncNonce, courier_id: courierId }) });
                const cityResult = await cityResponse.json();
                if (!cityResult.success) throw new Error(cityResult.data.message || 'Failed to sync cities.');
                
                const cities = cityResult.data.cities;
                if (!cities || cities.length === 0) throw new Error('No cities returned from API.');
                const totalCities = cities.length;
                let citiesProcessed = 0;

                for (const city of cities) {
                    syncStatus.textContent = `Syncing zones for ${city.city_name}... (${citiesProcessed + 1}/${totalCities})`;
                    const zoneResponse = await fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'oms_sync_zones', nonce: syncNonce, city_id: city.city_id, courier_id: courierId }) });
                    const zoneResult = await zoneResponse.json();
                    if (!zoneResult.success) { console.warn(`Could not sync zones for city ID ${city.city_id}`); continue; }

                    const zones = zoneResult.data.zones;
                    if (zones && zones.length > 0) {
                         for (const zone of zones) {
                            await fetch(ajaxUrl, { method: 'POST', body: new URLSearchParams({ action: 'oms_sync_areas', nonce: syncNonce, zone_id: zone.zone_id, courier_id: courierId }) });
                        }
                    }
                    citiesProcessed++;
                    progressBar.style.width = `${(citiesProcessed / totalCities) * 100}%`;
                }
                syncStatus.textContent = 'Sync completed successfully!';
            } catch (error) {
                syncStatus.textContent = `Error: ${error.message}`;
                console.error('Sync Error:', error);
            } finally {
                this.disabled = false;
            }
        });
    }

    const clearCacheBtn = document.getElementById('oms-clear-cache-btn');
    const cacheStatus = document.getElementById('oms-cache-status');
    const cacheNonce = "<?php echo wp_create_nonce('oms_cache_nonce'); ?>";
    
    if (clearCacheBtn) {
        clearCacheBtn.addEventListener('click', function() {
            this.disabled = true;
            cacheStatus.textContent = 'Clearing cache...';
            fetch(ajaxUrl, {
                method: 'POST',
                body: new URLSearchParams({ action: 'oms_clear_cache', nonce: cacheNonce })
            }).then(response => response.json()).then(result => {
                cacheStatus.textContent = result.success ? `Success: ${result.data.message}` : `Error: ${result.data.message}`;
            }).catch(error => {
                cacheStatus.textContent = 'An unexpected error occurred.';
            }).finally(() => {
                this.disabled = false;
            });
        });
    }
});
</script>
</div>
