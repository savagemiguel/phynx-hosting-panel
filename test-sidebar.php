<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sidebar Toggle Test</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
</head>
<body>
    <!-- Include the sidebar -->
    <?php include 'includes/admin_sidebar.php'; ?>
    
    <!-- Main content area -->
    <div class="main-content">
        <div style="padding: 20px;">
            <h1>Sidebar Toggle Test Page</h1>
            <p>Open browser console (F12) to see debug messages.</p>
            <p>Click the toggle button (hamburger icon) in the sidebar header to test functionality.</p>
            
            <div style="margin-top: 20px;">
                <h3>Test Instructions:</h3>
                <ol>
                    <li><strong>Open browser console (F12)</strong> to see debug messages</li>
                    <li><strong>Click the toggle button (☰)</strong> in the sidebar header</li>
                    <li><strong>When collapsed:</strong> Hover over navigation icons to see tooltips and submenu dropdowns</li>
                    <li><strong>Test navigation:</strong> Try clicking on submenu items that appear on hover</li>
                    <li><strong>Check console:</strong> Look for "Group toggle clicked" and sidebar state messages</li>
                    <li><strong>Toggle back:</strong> Click toggle button again to expand sidebar</li>
                </ol>
            </div>
            
            <div style="margin-top: 20px; padding: 15px; background: #f0f9ff; border-left: 4px solid #0ea5e9; border-radius: 4px;">
                <h4 style="margin: 0 0 10px 0; color: #0369a1;">✨ New Collapsed Sidebar Features:</h4>
                <ul style="margin: 0; color: #0c4a6e;">
                    <li><strong>Hover Tooltips:</strong> Show navigation group names when collapsed</li>
                    <li><strong>Dropdown Menus:</strong> Hover over icons to access all submenu items</li>
                    <li><strong>80px Width:</strong> Comfortable icon spacing (was 70px)</li>
                    <li><strong>Hidden Quick Stats:</strong> Clean icon-only design</li>
                </ul>
            </div>
            
            <div style="margin-top: 20px;">
                <h3>Current Sidebar State:</h3>
                <p id="sidebar-state">Loading...</p>
                <button onclick="checkSidebarState()">Refresh State</button>
            </div>
        </div>
    </div>
    
    <script src="assets/js/modern-sidebar.js"></script>
    <script>
        function checkSidebarState() {
            const sidebar = document.querySelector('.modern-sidebar');
            const toggleBtn = document.getElementById('toggleSidebarBtn');
            const stateDiv = document.getElementById('sidebar-state');
            
            const state = {
                sidebarExists: !!sidebar,
                sidebarCollapsed: sidebar ? sidebar.classList.contains('collapsed') : false,
                toggleBtnExists: !!toggleBtn,
                localStorageState: localStorage.getItem('sidebar-collapsed'),
                sidebarWidth: sidebar ? getComputedStyle(sidebar).width : 'N/A'
            };
            
            stateDiv.innerHTML = `
                <strong>Sidebar Element:</strong> ${state.sidebarExists ? 'Found' : 'NOT FOUND'}<br>
                <strong>Collapsed State:</strong> ${state.sidebarCollapsed ? 'Collapsed (80px)' : 'Expanded (280px)'}<br>
                <strong>Current Width:</strong> ${state.sidebarWidth}<br>
                <strong>Toggle Button:</strong> ${state.toggleBtnExists ? 'Found' : 'NOT FOUND'}<br>
                <strong>LocalStorage:</strong> ${state.localStorageState || 'null'}
            `;
            
            console.log('Sidebar state check:', state);
        }
        
        // Check state on page load
        setTimeout(checkSidebarState, 1000);
    </script>
</body>
</html>