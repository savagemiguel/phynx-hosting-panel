/**
 * Modern Sidebar Navigation JavaScript
 * Handles collapsible groups, search functionality, and interactive features
 */

// Multiple initialization strategies to ensure functionality works
function initializeEverything() {
    console.log('Modern sidebar initializing...');
    initializeSidebar();
    initializeSearch();
    initializeStatusUpdates();
    initializeLiveUpdates();
}

// Try multiple initialization methods
document.addEventListener('DOMContentLoaded', initializeEverything);

// Fallback for cases where DOMContentLoaded has already fired
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeEverything);
} else {
    // DOM is already ready
    setTimeout(initializeEverything, 100);
}

// Additional fallback
window.addEventListener('load', function() {
    console.log('Window load event - ensuring sidebar is initialized');
});

/**
 * Initialize sidebar functionality
 */
function initializeSidebar() {
    console.log('Initializing sidebar...');
    
    // Wait a moment for DOM to be fully ready
    setTimeout(function() {
        const groupToggles = document.querySelectorAll('.group-toggle');
        console.log('Found group toggles:', groupToggles.length);
        
        groupToggles.forEach(function(toggle, index) {
            console.log('Setting up toggle', index, toggle);
            
            // Remove any existing listeners
            toggle.removeEventListener('click', handleGroupToggle);
            
            // Add click listener
            toggle.addEventListener('click', handleGroupToggle);
        });
        
        // Set active navigation item based on current page
        setActiveNavItem();
        
        // Auto-expand group containing active item
        const activeItem = document.querySelector('.sub-item.active');
        if (activeItem) {
            const parentGroup = activeItem.closest('.nav-group');
            if (parentGroup) {
                parentGroup.classList.add('expanded');
                console.log('Auto-expanded group containing active item');
            }
        }
        
        console.log('Sidebar initialization complete');
    }, 100);
    
    // Handle quick action buttons
    initializeQuickActions();
}

/**
 * Handle group toggle clicks
 */
function handleGroupToggle(e) {
    e.preventDefault();
    e.stopPropagation();
    
    console.log('Group toggle clicked');
    
    const group = this.closest('.nav-group');
    if (!group) {
        console.log('No nav-group found for toggle');
        return;
    }
    
    const isExpanded = group.classList.contains('expanded');
    console.log('Group expanded state:', isExpanded);
    
    // Toggle current group
    if (isExpanded) {
        group.classList.remove('expanded');
        console.log('Collapsed group');
    } else {
        group.classList.add('expanded');
        console.log('Expanded group');
    }
    
    // Optional: Close other groups in same section
    const section = group.closest('.nav-section');
    if (section && !isExpanded) {
        section.querySelectorAll('.nav-group').forEach(function(g) {
            if (g !== group && g.classList.contains('expanded')) {
                g.classList.remove('expanded');
                console.log('Closed other group');
            }
        });
    }
}

/**
 * Debug DOM elements for troubleshooting
 */
function debugSidebarElements() {
    console.log('=== Sidebar Debug Info ===');
    const sidebar = document.querySelector('.modern-sidebar');
    const toggleBtn = document.getElementById('toggleSidebarBtn');
    const mainContent = document.querySelector('.main-content, .admin-content');
    
    console.log('Sidebar element:', sidebar);
    console.log('Toggle button:', toggleBtn);
    console.log('Main content:', mainContent);
    console.log('Sidebar classes:', sidebar ? sidebar.className : 'N/A');
    console.log('=========================');
}

/**
 * Initialize quick action buttons
 */
// Quick actions functionality removed

/**
 * Set active navigation item based on current URL
 */
function setActiveNavItem() {
    const currentPage = window.location.pathname.split('/').pop() || 'index.php';
    const navItems = document.querySelectorAll('.nav-item');
    
    navItems.forEach(item => {
        const href = item.getAttribute('href');
        if (href && (href.includes(currentPage) || 
            (currentPage === 'index.php' && href === 'index.php'))) {
            item.classList.add('active');
        }
    });
}

/**
 * Initialize search functionality
 */
function initializeSearch() {
    const searchInput = document.querySelector('.search-input');
    
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            filterNavigation(query);
        });
        
        // Handle search shortcuts
        document.addEventListener('keydown', function(e) {
            if ((e.ctrlKey || e.metaKey) && e.key === 'k') {
                e.preventDefault();
                searchInput.focus();
            }
        });
    }
}

/**
 * Filter navigation items based on search query
 */
function filterNavigation(query) {
    const navGroups = document.querySelectorAll('.nav-group');
    const subItems = document.querySelectorAll('.sub-item');
    
    if (!query) {
        // Reset all groups to default state
        navGroups.forEach(group => {
            group.style.display = 'block';
        });
        subItems.forEach(item => {
            item.style.display = 'flex';
            item.classList.remove('search-highlight');
        });
        return;
    }
    
    navGroups.forEach(group => {
        let groupHasMatches = false;
        const items = group.querySelectorAll('.sub-item');
        
        items.forEach(item => {
            const text = item.textContent.toLowerCase();
            const matches = text.includes(query);
            
            if (matches) {
                item.style.display = 'flex';
                item.classList.add('search-highlight');
                groupHasMatches = true;
            } else {
                item.style.display = 'none';
                item.classList.remove('search-highlight');
            }
        });
        
        // Show/hide group based on whether it has matches
        group.style.display = groupHasMatches ? 'block' : 'none';
        
        // Auto-expand groups with matches
        if (groupHasMatches) {
            group.classList.add('expanded');
        }
    });
}

/**
 * Initialize real-time status updates
 */
function initializeStatusUpdates() {
    updateSystemStatus();
    
    // Update status every 30 seconds
    setInterval(updateSystemStatus, 30000);
}

/**
 * Update system status indicators
 */
async function updateSystemStatus() {
    try {
        // This would typically fetch from an API endpoint
        // For now, we'll simulate with random values
        const statusItems = document.querySelectorAll('.status-item');
        
        // Simulate Apache status
        const apacheStatus = document.querySelector('.status-item:nth-child(1) .status-icon');
        if (apacheStatus) {
            apacheStatus.className = 'fas fa-server status-icon status-online';
        }
        
        // Simulate MySQL status
        const mysqlStatus = document.querySelector('.status-item:nth-child(2) .status-icon');
        if (mysqlStatus) {
            mysqlStatus.className = 'fas fa-database status-icon status-online';
        }
        
        // Simulate memory usage (random between 50-90%)
        const memoryItem = document.querySelector('.status-item:nth-child(3) .status-text');
        if (memoryItem) {
            const usage = Math.floor(Math.random() * 40) + 50;
            memoryItem.textContent = `Memory: ${usage}%`;
            
            const memoryIcon = document.querySelector('.status-item:nth-child(3) .status-icon');
            if (memoryIcon) {
                if (usage > 80) {
                    memoryIcon.className = 'fas fa-memory status-icon status-error';
                } else if (usage > 65) {
                    memoryIcon.className = 'fas fa-memory status-icon status-warning';
                } else {
                    memoryIcon.className = 'fas fa-memory status-icon status-online';
                }
            }
        }
        
        // Simulate disk usage (random between 30-70%)
        const diskItem = document.querySelector('.status-item:nth-child(4) .status-text');
        if (diskItem) {
            const usage = Math.floor(Math.random() * 40) + 30;
            diskItem.textContent = `Disk: ${usage}%`;
            
            const diskIcon = document.querySelector('.status-item:nth-child(4) .status-icon');
            if (diskIcon) {
                if (usage > 85) {
                    diskIcon.className = 'fas fa-hdd status-icon status-error';
                } else if (usage > 70) {
                    diskIcon.className = 'fas fa-hdd status-icon status-warning';
                } else {
                    diskIcon.className = 'fas fa-hdd status-icon status-online';
                }
            }
        }
        
    } catch (error) {
        console.error('Error updating system status:', error);
    }
}

/**
 * Notification badge animation
 */
function animateNotificationBadge(badge) {
    badge.style.transform = 'scale(1.2)';
    setTimeout(() => {
        badge.style.transform = 'scale(1)';
    }, 200);
}

/**
 * Initialize live updates for server stats
 */
function initializeLiveUpdates() {
    // Update server uptime every 30 seconds
    setInterval(updateServerUptime, 30000);
    
    // Update other server stats every 60 seconds
    setInterval(updateServerStats, 60000);
}

/**
 * Update server uptime in real-time
 */
function updateServerUptime() {
    const uptimeElements = document.querySelectorAll('.stat-card .stat-number');
    uptimeElements.forEach(function(element) {
        const label = element.parentElement.querySelector('.stat-label');
        if (label && label.textContent === 'System Uptime') {
            // Get current uptime from server or calculate locally
            fetch('?action=uptime')
                .then(response => response.json())
                .then(data => {
                    if (data.uptime) {
                        element.textContent = data.uptime;
                    }
                })
                .catch(error => {
                    console.log('Could not update uptime:', error);
                    // Fallback: increment locally
                    incrementUptimeLocally(element);
                });
        }
    });
}

/**
 * Increment uptime locally as fallback
 */
function incrementUptimeLocally(element) {
    const currentText = element.textContent;
    const match = currentText.match(/(\d+)d (\d+)h (\d+)m/);
    
    if (match) {
        let days = parseInt(match[1]);
        let hours = parseInt(match[2]);
        let minutes = parseInt(match[3]) + 1;
        
        if (minutes >= 60) {
            minutes = 0;
            hours++;
        }
        if (hours >= 24) {
            hours = 0;
            days++;
        }
        
        element.textContent = `${days}d ${hours}h ${minutes}m`;
    }
}

/**
 * Update other server stats
 */
function updateServerStats() {
    fetch('?action=stats')
        .then(response => response.json())
        .then(data => {
            if (data.memory) {
                updateStatCard('Memory Usage', data.memory.percent, data.memory.used + ' / ' + data.memory.total);
            }
            if (data.disk) {
                updateStatCard('Disk Usage', data.disk.percent, data.disk.used + ' / ' + data.disk.total);
            }
            if (data.cpu) {
                updateStatCard('CPU Load (1min)', data.cpu.load_1min, data.cpu.load_5min + ' | ' + data.cpu.load_15min);
            }
        })
        .catch(error => {
            console.log('Could not update server stats:', error);
        });
}

/**
 * Update individual stat card
 */
function updateStatCard(labelText, value, detail) {
    const statCards = document.querySelectorAll('.stat-card');
    statCards.forEach(function(card) {
        const label = card.querySelector('.stat-label');
        if (label && label.textContent === labelText) {
            const number = card.querySelector('.stat-number');
            const detailElement = card.querySelector('.stat-detail');
            
            if (number) {
                if (labelText.includes('Usage')) {
                    number.textContent = Math.round(value) + '%';
                    // Update progress bar
                    card.style.setProperty('--progress-width', Math.round(value) + '%');
                } else {
                    number.textContent = value;
                }
            }
            
            if (detailElement && detail) {
                detailElement.textContent = detail;
            }
        }
    });
}

// Overlay functionality removed

/**
 * Smooth scroll to section (if needed for internal navigation)
 */
function scrollToSection(selector) {
    const element = document.querySelector(selector);
    if (element) {
        element.scrollIntoView({ 
            behavior: 'smooth',
            block: 'start'
        });
    }
}