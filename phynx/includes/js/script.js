// @ts-nocheck
const navToggle = document.getElementById('mobileNavToggle');
const navBackdrop = document.getElementById('mobileNavBackdrop');
const navigation = document.getElementById('navigation');
const navToggleTab = document.getElementById('navToggleTab');
const main = document.getElementById('main');
const html = document.documentElement;

document.addEventListener("DOMContentLoaded", function () {
	// Auto-resize SQL textarea
	const textareas = document.querySelectorAll("textarea");
	textareas.forEach((textarea) => {
		textarea.addEventListener("input", function () {
			this.style.height = "auto";
			this.style.height = this.scrollHeight + "px";
		});
	});

	// Confirm delete actions
	const deleteLinks = document.querySelectorAll('a[href*="delete"]');
	deleteLinks.forEach((link) => {
		link.addEventListener("click", function (e) {
			if (!confirm("Are you sure you want to delete this item?")) {
				e.preventDefault();
			}
		});
	});

	// File input enhancement
	document.querySelectorAll('input[type="file"]').forEach((input) => {
		input.addEventListener("change", function () {
			const label = this.nextElementSibling;
			if (this.files.length > 0) {
				label.classList.add("file-selected");
				label.querySelector("span").textContent = this.files[0].name;
			} else {
				label.classList.remove("file-selected");
				label.querySelector("span").textContent = "Choose SQL file or drag and drop";
			}
		});
	});

	// Handle database tree toggle
	document.querySelectorAll('.db-header').forEach((header) => {
		header.addEventListener('click', function (e) {
			e.preventDefault();

			const dbItem = this.closest('.db-item');
			const toggleIcon = this.querySelector('.toggle-icon');
			const tablesContainer = dbItem.querySelector('.tables');
			const dbName = this.getAttribute('data-db');

			if (dbItem.classList.contains('expanded')) {
				// Collapse with animation
				tablesContainer.style.maxHeight = '0px';
				dbItem.classList.remove('expanded');
				toggleIcon.classList.remove('fa-minus');
				toggleIcon.classList.add('fa-plus');

				// Wait for transition to finish before clearing content
				tablesContainer.addEventListener('transitionend', function handler() {
					tablesContainer.innerHTML = '';
					tablesContainer.removeEventListener('transitionend', handler);
				});
			} else {
				// Expand
				dbItem.classList.add('expanded');
				toggleIcon.classList.remove('fa-plus');
				toggleIcon.classList.add('fa-minus');
				
				if (tablesContainer.innerHTML.trim() !== '') {
					tablesContainer.style.maxHeight = tablesContainer.scrollHeight + "px";
					return;
				}
				

				// AJAX request to get tables
				tablesContainer.innerHTML = '<div class="loading">Loading...</div>';
				fetch('get_tables.php', {
					method: 'POST',
					headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
					body: 'db=' + encodeURIComponent(dbName)
				})
				.then(response => response.json())
				.then(tables => {
					let tableLinks = '';

					// Add "View Database" link first
					tableLinks += `<a href="?db=${encodeURIComponent(dbName)}" class="table-link" data-nav="true">
						<i class="fas fa-database table-icon"></i> View Database
					</a>`;

					// Add individual table links
					if (tables.length) {
						tableLinks += tables.map(table =>
							`<a href="?db=${encodeURIComponent(dbName)}&table=${encodeURIComponent(table)}" class="table-link" data-nav="true"><i class="fas fa-table table-icon"></i> ${table}</a>`
						).join('');
					} else {
						tableLinks += '<div class="no-tables">No tables found.</div>';
					}

					tablesContainer.innerHTML = tableLinks;
					
					// Set maxHeight after content is loaded
					tablesContainer.style.maxHeight = tablesContainer.scrollHeight + "px";
				})
				.catch(() => {
					tablesContainer.innerHTML = '<div class="error">ERROR loading tables.</div>';
					tablesContainer.style.maxHeight = tablesContainer.scrollHeight + "px";
				});
			}
		});
	});

	// Initialize expanded states
	document.querySelectorAll('.db-item.expanded .db-header').forEach(header => {
		header.click();
	});

	// Initialize Navbar Hide Tab
	navToggle.addEventListener('click', function() {
		navigation.classList.add('mobile-open');
		navBackdrop.classList.add('show');
		html.classList.add('no-scroll');
		document.body.classList.add('mobile-nav-active');
	});

	navBackdrop.addEventListener('click', function() {
		navigation.classList.remove('mobile-open');
		navBackdrop.classList.remove('show');
		html.classList.remove('no-scroll');
		document.body.classList.remove('mobile-nav-active');
	});

	navigation.addEventListener('click', function(e) {
		const link = e.target.closest('a');
		if (!link) return;
		if (link.matches('[data-nav="true"]')) {
			navigation.classList.remove('mobile-open');
			navBackdrop.classList.remove('show');
			html.classList.remove('no-scroll');
			document.body.classList.remove('mobile-nav-active');
		}
	});

	// Set navToggleTab arrow on initial load
	if (navToggleTab && navigation) {
		navToggleTab.querySelector('span').textContent = navigation.classList.contains('closed') ? '>' : '<';
	}

	if (navToggleTab && navigation && main) {
		navToggleTab.addEventListener('click', function() {
			navigation.classList.toggle('closed');
			main.classList.toggle('sidebar-closed', navigation.classList.contains('closed'));
			navToggleTab.querySelector('span').textContent = navigation.classList.contains('closed') ? '>' : '<';
		});
	
	}

	// Start polling for system stats if the elements are on the page
	pollSystemStats();

	// Initialize Navigation Resize
	// initNavigationResize();

	// Initiate ON UPDATE checkbox
	const typeSelects = document.querySelectorAll('select[name^="column_type_"]');
	typeSelects.forEach(select => {
		select.addEventListener('change', function() {
			handleColumnTypeChange(this);
		});
	});
	
	// Theme switcher
	const themeSelect = document.getElementById('theme-select');
	console.log('Theme select found:', themeSelect);

	if (themeSelect) {
		themeSelect.addEventListener('change', function() {
			// Change stylesheet
			const styleLink = document.querySelector('link[href*="styles.css"]');
			if (styleLink) {
				styleLink.href = `includes/css/themes/${this.value}.css`;
			}
		});
	}

	// Initialize installation progress if on install page
	if (window.location.pathname.includes('install.php')) {
		const urlParams = new URLSearchParams(window.location.search);
		const currentStep = parseInt(urlParams.get('step') || 1);
		const totalSteps = 5; // Replace with the actual total number of steps

		updateProgress(currentStep, totalSteps);

		// Start completion animation on step 5
		if (currentStep === totalSteps) {
			setTimeout(completeInstallation, 1000);
		}
	}

	// Detect Keyboard Users
	const keyboardClass = "keyboardUser";

	function setKeyboardUser(isKeyboard) {
		const { body } = document;
		if (isKeyboard) {
			body.classList.contains(keyboardClass) || body.classList.add(keyboardClass);
		} else {
			body.classList.remove(keyboardClass);
		}
	}

	document.addEventListener('keydown', e => {
		if (e.key === 'Tab') {
			setKeyboardUser(true);
		}
	});

	document.addEventListener('click', e => {
		// Pressing ENTER on buttons triggers a click event
		setKeyboardUser(!e.screenX && !e.screenY);
	});

	document.addEventListener('mousedown', e => {
		setKeyboardUser(false);
	});
});

// Live system stats polling
function pollSystemStats() {
	// Get elements
	const elements = {
		cpu: document.getElementById('cpu-usage'),
		ram: document.getElementById('ram-usage'),
		disk: document.getElementById('disk-usage'),
		uptime: document.getElementById('uptime'),
	};

	// Check to make sure all elements are loaded on the page first
	if (!elements.cpu || !elements.ram || !elements.disk || !elements.uptime) {
		return; // null
	}

	const fetchSystemStats = () => {
		fetch('system_stats.php')
		    .then((response) => {
				if (!response.ok) {
					throw new Error(`Network response was not OK, status: ${response.status}`);
				}
				return response.json();
			})
			.then((data) => {
				// Update text content and title for tooltips
				elements.cpu.textContent = data.cpu_usage || "N/A";
				elements.cpu.title = data.cpu_details || "";
				elements.ram.textContent = data.ram_usage || "N/A";
				elements.ram.title = data.ram_details || "";
				elements.disk.textContent = data.disk_usage || "N/A";
				elements.disk.title = data.disk_details || "";
				elements.uptime.textContent = data.uptime || "N/A";
				elements.uptime.title = data.uptime_details || "";
			})
			.catch((error) => {
				console.error("ERROR: ", error);
				// On ERROR, show specific state in the UI
				Object.values(elements).forEach(el => el.textContent = 'ERROR');
			});
	};

	// Initial call to load stats immediately
	fetchSystemStats();

	// Setup polling every 5 seconds
	setInterval(fetchSystemStats, 60000);
}

// Global function for server switching
// Initialize server switcher
function changeServer(serverId) {
	fetch("change_server.php", {
		method: "POST",
		headers: { "Content-Type": "application/x-www-form-urlencoded" },
		body: "server_id=" + serverId,
	})
		.then((response) => response.text())
		.then((result) => {
			if (result === "success") {
				location.reload();
			} else {
				alert("Error switching server: " + result);
			}
		});
}

// Global function for config file saving
// Initialize config file saving
function saveConfig() {
	const configContent = document.querySelector("textarea").value;

	fetch("save_config.php", {
		method: "POST",
		headers: { "Content-Type": "application/x-www-form-urlencoded" },
		body: "config_content=" + encodeURIComponent(configContent),
	})
		.then((response) => response.text())
		.then((result) => {
			if (result === "success") {
				alert("Configuration saved successfully.");
			} else {
				alert("Error saving configuration: " + result);
			}
		});
}

// Global function for PHP ini saving
// Initialize PHP ini
function savePHPIni() {
	const iniContent = document.querySelector("#php-ini-textarea").value;

	fetch("save_php_ini.php", {
		method: "POST",
		headers: { "Content-Type": "application/x-www-form-urlencoded" },
		body: "ini_content=" + encodeURIComponent(iniContent),
	})
		.then((response) => response.text())
		.then((result) => {
			if (result === "success") {
				alert(
					"PHP configuration saved successfully! Restart your web server for changes to take effect."
				);
			} else {
				alert("Error saving PHP configuration: " + result);
			}
		});
}

// Global functions for user account creation
// Update host input based on dropdown selection
function updateHostInput(value) {
	const hostInput = document.getElementById('host_input');
	hostInput.classList.add('disabled');
	switch (value) {
		case 'any':
			hostInput.value = '%';
			hostInput.readOnly = true;
			break;
		case 'localhost':
			hostInput.value = 'localhost';
			hostInput.readOnly = true;
			break;
		case 'this_host':
			hostInput.value = window.location.hostname;
			hostInput.readOnly = true;
			break;
		case 'custom':
			hostInput.value = '';
			hostInput.readOnly = false;
			hostInput.focus();
			hostInput.classList.remove('disabled');
			break;
	}
}

// Check password strength
function checkPasswordStrength(password) {
	const strengthBar = document.querySelector('.strength-bar');
	const strengthText = document.querySelector('.strength-text');

	// Remove all classes first
	strengthBar.classList.remove('weak', 'medium', 'strong');

	if (!password) {
		strengthText.textContent = '';
		return;
	}

	/**
	 * Helpers to check password strength
	 * Must have 8+ characters, numbers
	 * Must have at least 1 (or more) special character(s)
	 **/

	let strength = 0; // reset to 0
	if (password.length >= 8) strength += 2; // length check
	if(/\d/.test(password)) strength += 1; // contains number(s)
	if (/[a-zA-Z]/.test(password)) strength += 1; // contains letter(s)
	if (/[^A-Za-z0-9]/.test(password)) strength += 1; // contains special character(s)

	// Update the UI based on strength
	// These can be changed to whatever is needed for strength
	if (strength < 2) {
		strengthBar.classList.add('weak');
		strengthText.textContent = 'Weak 😩';
		strengthText.style.color = 'var(--error-color)';
	} else if (strength < 4) {
		strengthBar.classList.add('medium');
		strengthText.textContent = 'Medium 🤔';
		strengthText.style.color = 'var(--warning-color)';
	} else {
		strengthBar.classList.add('strong');
		strengthText.textContent = 'Strong 💪';
		strengthText.style.color = 'var(--success-color)';
	}
}

// Toggle the password strength bar
function togglePassword(inputId) {
	const input = document.getElementById(inputId);
	const icon = input.nextElementSibling;

	if (input.type === 'password') {
		input.type = 'text';
		icon.classList.replace('fa-eye', 'fa-eye-slash');
	} else {
		input.type = 'password';
		icon.classList.replace('fa-eye-slash', 'fa-eye');
	}
}

// Check password match
function checkPasswordMatch() {
	const password = document.getElementById('password');
	const confirm = document.getElementById('password_confirm');

	if (!password || !confirm) return;
	if (confirm.value === '') {
		confirm.style.borderColor = 'var(--border-color)';
		return;
	}

	if (password.value === confirm.value) {
		confirm.style.borderColor = 'var(--success-color)';
	} else {
		confirm.style.borderColor = 'var(--error-color)';
	}
}

// Generate random password
function generatePassword() {
	// Add loading state
	const btn = document.querySelector('.generate-btn');
	btn.classList.add('loading');

	// Simulate a brief delay for the animation
	setTimeout(() => {
		// Define character sets
		const uppercase = "ABCDEFGHIJKLMNOPQRSTUVWXYZ";
		const lowercase = "abcdefghijklmnopqrstuvwxyz";
		const numbers = "0123456789";
		const special = "!@#$%^&*()";

		// Initialize password with one character from each required set
		let password = [
			uppercase[Math.floor(Math.random() * uppercase.length)],
			lowercase[Math.floor(Math.random() * lowercase.length)],
			numbers[Math.floor(Math.random() * numbers.length)],
			special[Math.floor(Math.random() * special.length)]
		];

		// Fill in the rest of the password length (8 characters minimum)
		const allChars = uppercase + lowercase + numbers + special;
		for (let n = 0; n < 8; n++) {
			password.push(allChars[Math.floor(Math.random() * allChars.length)]);
		}

		// Shuffle the characters array to make it random
		password = password.sort(() => Math.random() - 0.5).join('');

		// Set both password fields with the generated password
		document.getElementById('password').value = password;
		document.getElementById('password_confirm').value = password;

		// Update strength and match indicators
		checkPasswordStrength(password);
		checkPasswordMatch();

		// Remove loading state
		btn.classList.remove('loading');
	}, 600); // Show spinner for 600ms to prevent clipping
	
}

// Toggle all privileges except GRANT
function toggleAllPrivileges() {
	const checkAll = document.getElementById("checkAll");
	const privilegeCheckboxes = document.querySelectorAll('input[name="privileges[]"]:not(#grantCheckbox)');
	const grantWarning = document.getElementById("grantWarning");

	privilegeCheckboxes.forEach((checkbox) => {
		checkbox.checked = checkAll.checked;
	});

	if (checkAll.checked) {
		grantWarning.style.display = "block";
	} else {
		grantWarning.style.display = "none";
	}
}

// Toggle GRANT warning and confirmation
function toggleGrantWarning() {
	const grantCheckbox = document.getElementById("grantCheckbox");
	const grantConfirmation = document.getElementById("grantConfirmation");
	const confirmCheckbox = document.querySelector('input[name="confirm_grant"]');
	const username = document.querySelector('input[name="username"]').value || "[username]";
	const hostname = document.querySelector('input[name="hostname"]').value || "[hostname]";

	if (grantCheckbox.checked) {
		grantConfirmation.style.display = "block";
		confirmCheckbox.required = true;
		// Update confirmation label with username
		const label = grantConfirmation.querySelector('label');
		label.innerHTML = `<input type="checkbox" name="confirm_grant"> YES, I want to create ${username}@${hostname} with GRANT permission`;
	} else {
		grantConfirmation.style.display = "none";
		confirmCheckbox.required = false;
		confirmCheckbox.checked = false;
	}
}

// Toggle SSL options
function toggleSSLOptions() {
	const sslOptions = document.getElementById("sslOptions");
	const specifiedRadio = document.querySelector('input[value="SPECIFIED"]');

	if (specifiedRadio.checked) {
		sslOptions.style.display = "block";
	} else {
		sslOptions.style.display = "none";
	}
}

// Update GRANT confirmation with username
document.querySelector('input[name="username"]').addEventListener("input", function () {
		const grantCheckbox = document.getElementById("grantCheckbox");
		if (grantCheckbox.checked) {
			toggleGrantWarning();
		}
	});

/** function iniNavigationResize() {
	const nav = document.getElementById('navigation');
	if (!nav) return;

	// Create resizer element
	const resizer = document.createElement('div');
	resizer.className = 'nav-resizer';
	nav.appendChild(resizer);

	let isResizing = false;

	resizer.addEventListener('mousedown', (e) => {
		isResizing = true;
		document.addEventListener('mousemove', resize);
		document.addEventListener('mouseup', stopResize);
		e.preventDefault();
	});

	function resize(e) {
		if (!isResizing) return;
		const newWidth = e.clientX - nav.offsetLeft;
		if (newWidth >= 300 && newWidth <= 500) {
			nav.style.width = newWidth + 'px';
		}
	}

	function stopResize() {
		isResizing = false;
		document.removeEventListener('mousemove', resize);
		document.removeEventListener('mouseup', stopResize);
	}
} **/

// Global function to update the progress bar
// Installation progress and completion functions
function updateProgress(currentStep, totalSteps) {
	const progress = (currentStep / totalSteps) * 100;
	const progressBar = document.getElementById('topProgress');
	if (progressBar) {
		progressBar.style.width = progress + '%';
	}
}

function animateDots(element) {
	let dotCount = 0;
	return setInterval(() => {
		dotCount = (dotCount + 1) % 4;
		element.textContent = '.'.repeat(dotCount);
	}, 500);
}

function completeInstallation() {
	const checks = ['db-check', 'config-check', 'security-check'];
	let currentCheck = 0;

	function processNext() {
		if (currentCheck >= checks.length) return;
		
		const checkElement = document.getElementById(checks[currentCheck]);
		if (!checkElement) return;

		const dotsElement = checkElement.querySelector('.dots');
		const checkIcon = checkElement.querySelector('.completion-check');

		if (dotsElement && checkIcon) {
			// Start dot animation
			const dotInterval = animateDots(dotsElement);

			// Complete after 2 seconds
			setTimeout(() => {
				clearInterval(dotInterval);
				dotsElement.style.display = 'none';
				checkIcon.style.display = 'inline';
				currentCheck++;
				processNext();
			}, 2000);
		}
	}

	processNext();
}

function toggleTableOptions() {
	const checkbox = document.getElementById('create_table');
	const options = document.getElementById('table_options');
	options.style.display = checkbox.checked ? 'block' : 'none';
}

function toggleAllColumns(source) {
	const checkboxes = document.querySelectorAll('input[name="columns[]"]');
	checkboxes.forEach(db => cb.checked = source.checked);
}

function checkAllColumns() {
	const checkboxes = document.querySelectorAll('input[name="columns[]"]');
	checkboxes.forEach(cb => cb.checked = true);
}

function toggleNullValue(checkbox, fieldName) {
	const valueField = document.getElementById('value_' + fieldName);
	if (checkbox.checked) {
		valueField.disabled = true;
		valueField.style.backgroundColor = '#F0F0F0';
	} else {
		valueField.disabled = false;
		valueField.style.backgroundColor = '';
	}
}

function toggleDateTimePicker(fieldName) {
	const popup = document.getElementById('datetime-popup-' + fieldName);
	popup.style.display = popup.style.display === 'none' ? 'block' : 'none';

	if (popup.style.display === 'block') {
		initializePicker(fieldName);
	}
}

function initializePicker(fieldName) {
	const yearSelect = document.getElementById('year-select-' + fieldName);
	const currentYear = new Date().getFullYear();
	yearSelect.innerHTML = '';
	for (let year = currentYear - 10; year <= currentYear + 10; year++) {
		yearSelect.innerHTML += `<option value="${year}">${year}</option>`;
	}

	const now = new Date();
	document.getElementById('month-select-' + fieldName).value = now.getMonth();
	document.getElementById('year-select-' + fieldName).value = now.getFullYear();
	document.getElementById('hour-slider-' + fieldName).value = now.getHours();
	document.getElementById('minute-slider-' + fieldName).value = now.getMinutes();
	document.getElementById('second-slider-' + fieldName).value = now.getSeconds();

	updateCalendar(fieldName);
}

function updateCalendar(fieldName) {
	const month = parseInt(document.getElementById('month-select-' + fieldName).value);
	const year = parseInt(document.getElementById('year-select-' + fieldName).value);
	const firstDay = new Date(year, month, 1).getDay();
	const daysInMonth = new Date(year, month + 1, 0).getDate();

	const calendarDays = document.getElementById('calendar-days-' + fieldName);
	calendarDays.innerHTML = '';

	for (let i = 0; i < firstDay; i++) {
		calnedarDays.innerHTML += '<div></div>';
	}

	for (let day = 1; day <= daysInMonth; day++) {
		calendarDays.innerHTML += `<div class="calendar-day" onclick="selectDate(${day}, '${fieldName}')">${day}</div>`;
	}
}

function selectDate(day, fieldName) {
	document.querySelectorAll('#calendar-days-' + fieldName + ' .calendar-day').forEach(el => el.classList.remove('selected'));
	event.target.classList.add('selected');
	window['selectedDay_' + fieldName] = day;
}

// Handle date/time applicator
function applyDateTime(fieldName) {
	const year = document.getElementById('year-select-' + fieldName).value;
	const month = (parseInt(document.getElementById('month-select-' + fieldName).value) + 1).toString().padStart(2, '0');
	const day = (window['selectedDay_' + fieldName] || new Date().getDate()).toString().padStart(2, '0');
	const hour = document.getElementById('hour-slider-' + fieldName).value.padStart(2, '0');
	const minute = document.getElementById('minute-slider-' + fieldName).value.padStart(2, '0');
	const second = document.getElementById('second-slider-' + fieldName).value.padStart(2, '0');

	const dateTimeString = `${month}-${day}-${year} ${hour}:${minute}:${second}`;
	document.getElementById('value_' + fieldName).value = dateTimeString;
	document.getElementById('datetime-popup-' + fieldName).style.display = 'none';
}

// Handle AUTO_INCREMENT checkbox behavior
function handleAutoIncrement(checkbox) {
	const row = checkbox.closest('tr');
	const nullCheckbox = row.querySelector('input[name*="null"]');
	const defaultInput = row.querySelector('input[name*="default"]');

	if (checkbox.checked) {
		// Uncheck NULL and disable it
		if (nullCheckbox) {
			nullCheckbox.checked = false;
			nullCheckbox.disabled = true;
		}
		// Clear and disable default value
		if (defaultInput) {
			defaultInput.value = '';
			defaultInput.disabled = true;
			defaultInput.style.backgroundColor = '#F0F0F0';
		}
	} else {
		// Re-enable NULL checkbox
		if (nullCheckbox) {
			nullCheckbox.disabled = false;
		}
		// Re-enable default input
		if (defaultInput) {
			defaultInput.disabled = false;
			defaultInput.style.backgroundColor = '';
		}
	}
}

// Password toggle for login/register
function toggleLoginPassword() {
	const field = document.getElementById('password');
	const toggle = document.querySelector('.password-toggle');

	if (field.type === 'password') {
		field.type = 'text';
		toggle.classList.remove('fa-eye');
		toggle.classList.add('fa-eye-slash');
	} else {
		field.type = 'password';
		toggle.classList.remove('fa-eye-slash');
		toggle.classList.add('fa-eye');
	}
}

// Handle column type changes for timestamp defauls and ON UPDATE
function handleColumnTypeChange(selectElement) {
	const row = selectElement.closest('tr');
	const index = selectElement.name.match(/\d+/)[0];
	const defaultInput = row.querySelector('input[name*="[default]"]');
	const onUpdateCheckbox = document.getElementById(`on_update_${index}`);
	const onUpdateLabel = document.querySelector(`label[for="on_update_${index}"]`);
	const noUpdateSpan = row.querySelector('.no-update');
	const selectedType = selectElement.value.toUpperCase();

	if (selectedType === 'TIMESTAMP') {
		// Auto-fill CURRENT_TIMESTAMP for timestamp columns
		if (defaultInput) {
			defaultInput.value = 'CURRENT_TIMESTAMP';
		}

		// Show ON UPDATE option for timestamp columns
		if (onUpdateCheckbox) {
			onUpdateCheckbox.style.display = 'inline-block';
			onUpdateLabel.style.display = 'inline';
			noUpdateSpan.style.display = 'none';
			onUpdateCheckbox.checked = true;
		}
	} else {
		// Clear CURRENT_TIMESTAMP if switching away from timestamp
		if (defaultInput && defaultInput.value === 'CURRENT_TIMESTAMP') {
			defaultInput.value = '';
		}

		// Hide ON UPDATE option for non-timestamp types
		if (onUpdateCheckbox) {
			onUpdateCheckbox.style.display = 'none';
			onUpdateLabel.style.display = 'none';
			noUpdateSpan.style.display = 'inline';
			onUpdateCheckbox.checked = false;
		}
	}
}

// Function to handle new column creation type changes
function handleNewColumnTypeChange(selectElement) {
	const defaultInput = document.querySelector('input[name="new_default"]');
	const selectedType = selectElement.value.toUpperCase();

	if (selectedType === 'TIMESTAMP') {
		defaultInput.value = 'CURRENT_TIMESTAMP';
	} else {
		if (defaultInput.value === 'CURRENT_TIMESTAMP') {
			defaultInput.value = '';
		}
	}
}