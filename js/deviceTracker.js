// Enhanced Device Tracker with Advanced Features
// Location: /js/deviceTracker.js

class DeviceTracker {
    constructor() {
        this.config = {
            updateInterval: 2 * 60 * 1000, // 2 minutes
            endpoint: '../auth/trackDevice.php',
            storageKey: 'device_serial',
            debug: true
        };
        
        this.deviceInfo = null;
        this.updateTimer = null;
        this.isOnline = navigator.onLine;
        
        this.init();
    }

    log(message, data = null) {
        if (this.config.debug) {
            console.log(`[DeviceTracker] ${message}`, data || '');
        }
    }

    async init() {
        this.log('Initializing Device Tracker...');
        
        // Setup event listeners
        this.setupEventListeners();
        
        // Get and send initial device info
        await this.sendDeviceInfo();
        
        // Start periodic updates
        this.startPeriodicUpdates();
        
        this.log('Device Tracker initialized successfully');
    }

    setupEventListeners() {
        // Track online/offline status
        window.addEventListener('online', () => {
            this.log('Connection restored - sending update');
            this.isOnline = true;
            this.sendDeviceInfo();
        });

        window.addEventListener('offline', () => {
            this.log('Connection lost');
            this.isOnline = false;
        });

        // Track visibility changes (tab switch)
        document.addEventListener('visibilitychange', () => {
            if (!document.hidden) {
                this.log('Tab became visible - sending update');
                this.sendDeviceInfo();
            }
        });

        // Track page focus
        window.addEventListener('focus', () => {
            this.log('Window focused - sending update');
            this.sendDeviceInfo();
        });

        // Send final update before page unload
        window.addEventListener('beforeunload', () => {
            this.sendDeviceInfo(true); // Synchronous final update
        });

        // Track significant user activity
        let activityTimeout;
        const resetActivityTimer = () => {
            clearTimeout(activityTimeout);
            activityTimeout = setTimeout(() => {
                this.log('User activity detected - sending update');
                this.sendDeviceInfo();
            }, 30000); // Send update after 30 seconds of activity
        };

        ['mousedown', 'keydown', 'scroll', 'touchstart'].forEach(event => {
            document.addEventListener(event, resetActivityTimer, { passive: true });
        });
    }

    async generateDeviceFingerprint() {
        // Collect comprehensive device characteristics
        const characteristics = [
            navigator.userAgent,
            navigator.language,
            navigator.languages?.join(',') || '',
            screen.colorDepth,
            screen.width,
            screen.height,
            screen.availWidth,
            screen.availHeight,
            new Date().getTimezoneOffset(),
            navigator.hardwareConcurrency || 0,
            navigator.platform,
            navigator.maxTouchPoints || 0,
            navigator.vendor || '',
            window.devicePixelRatio || 1
        ];

        // Add canvas fingerprint
        try {
            const canvas = document.createElement('canvas');
            const ctx = canvas.getContext('2d');
            ctx.textBaseline = 'top';
            ctx.font = '14px Arial';
            ctx.fillText('Device Fingerprint', 2, 2);
            characteristics.push(canvas.toDataURL());
        } catch (e) {
            this.log('Canvas fingerprint failed', e);
        }

        // Add WebGL fingerprint
        try {
            const gl = document.createElement('canvas').getContext('webgl');
            if (gl) {
                const debugInfo = gl.getExtension('WEBGL_debug_renderer_info');
                if (debugInfo) {
                    characteristics.push(gl.getParameter(debugInfo.UNMASKED_VENDOR_WEBGL));
                    characteristics.push(gl.getParameter(debugInfo.UNMASKED_RENDERER_WEBGL));
                }
            }
        } catch (e) {
            this.log('WebGL fingerprint failed', e);
        }

        const data = characteristics.join('|');

        // Generate SHA-256 hash
        try {
            const buffer = new TextEncoder().encode(data);
            const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
            const hashArray = Array.from(new Uint8Array(hashBuffer));
            const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
            return 'DEV-' + hashHex.substring(0, 16).toUpperCase();
        } catch (error) {
            this.log('Hash generation failed, using fallback', error);
            return 'DEV-' + Math.random().toString(36).substring(2, 15).toUpperCase();
        }
    }

    async getBatteryInfo() {
        if ('getBattery' in navigator) {
            try {
                const battery = await navigator.getBattery();
                return {
                    level: Math.round(battery.level * 100),
                    charging: battery.charging,
                    chargingTime: battery.chargingTime,
                    dischargingTime: battery.dischargingTime
                };
            } catch (e) {
                this.log('Battery info not available', e);
            }
        }
        return null;
    }

    getNetworkInfo() {
        const connection = navigator.connection || navigator.mozConnection || navigator.webkitConnection;
        if (connection) {
            return {
                effectiveType: connection.effectiveType || 'unknown',
                downlink: connection.downlink || 0,
                rtt: connection.rtt || 0,
                saveData: connection.saveData || false
            };
        }
        return null;
    }

    getMemoryInfo() {
        if ('memory' in performance) {
            return {
                jsHeapSizeLimit: performance.memory.jsHeapSizeLimit,
                totalJSHeapSize: performance.memory.totalJSHeapSize,
                usedJSHeapSize: performance.memory.usedJSHeapSize
            };
        }
        return null;
    }

    getPerformanceInfo() {
        if ('timing' in performance) {
            const timing = performance.timing;
            return {
                pageLoadTime: timing.loadEventEnd - timing.navigationStart,
                domReadyTime: timing.domContentLoadedEventEnd - timing.navigationStart,
                connectTime: timing.responseEnd - timing.requestStart
            };
        }
        return null;
    }

    async getDeviceInfo() {
        let deviceSerial = localStorage.getItem(this.config.storageKey);
        
        if (!deviceSerial) {
            this.log('Generating new device fingerprint...');
            deviceSerial = await this.generateDeviceFingerprint();
            localStorage.setItem(this.config.storageKey, deviceSerial);
        }

        const info = {
            // Basic Device Info
            serial: deviceSerial,
            userAgent: navigator.userAgent,
            platform: navigator.platform,
            language: navigator.language,
            languages: navigator.languages || [],
            
            // Screen Info
            screenResolution: `${screen.width}x${screen.height}`,
            availableResolution: `${screen.availWidth}x${screen.availHeight}`,
            colorDepth: screen.colorDepth,
            pixelRatio: window.devicePixelRatio || 1,
            
            // System Info
            timezone: Intl.DateTimeFormat().resolvedOptions().timeZone,
            timezoneOffset: new Date().getTimezoneOffset(),
            cores: navigator.hardwareConcurrency || null,
            touchPoints: navigator.maxTouchPoints || 0,
            vendor: navigator.vendor || 'unknown',
            
            // Browser Info
            cookieEnabled: navigator.cookieEnabled,
            doNotTrack: navigator.doNotTrack || 'unspecified',
            
            // Connection Info
            online: navigator.onLine,
            connectionType: this.getNetworkInfo(),
            
            // Performance Info
            performance: this.getPerformanceInfo(),
            memory: this.getMemoryInfo(),
            
            // Battery Info (if available)
            battery: await this.getBatteryInfo(),
            
            // Page Info
            pageUrl: window.location.href,
            referrer: document.referrer || 'direct',
            
            // Timestamp
            timestamp: new Date().toISOString(),
            localTime: new Date().toLocaleString()
        };

        this.deviceInfo = info;
        return info;
    }

    async sendDeviceInfo(isBeforeUnload = false) {
        if (!this.isOnline) {
            this.log('Offline - skipping update');
            return;
        }

        try {
            const deviceInfo = await this.getDeviceInfo();
            this.log('Sending device info:', deviceInfo);

            if (isBeforeUnload) {
                // Use sendBeacon for reliability during page unload
                const blob = new Blob([JSON.stringify(deviceInfo)], { type: 'application/json' });
                navigator.sendBeacon(this.config.endpoint, blob);
                this.log('Sent via sendBeacon');
                return;
            }

            const response = await fetch(this.config.endpoint, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                },
                body: JSON.stringify(deviceInfo),
                credentials: 'same-origin',
                keepalive: true
            });

            const result = await response.json();
            this.log('Server response:', result);

            if (!response.ok) {
                console.error('[DeviceTracker] Failed to send device info:', result);
            }

            return result;

        } catch (error) {
            console.error('[DeviceTracker] Error tracking device:', error);
            return null;
        }
    }

    startPeriodicUpdates() {
        if (this.updateTimer) {
            clearInterval(this.updateTimer);
        }

        this.updateTimer = setInterval(() => {
            this.log('Periodic update triggered');
            this.sendDeviceInfo();
        }, this.config.updateInterval);

        this.log(`Periodic updates started (every ${this.config.updateInterval / 1000}s)`);
    }

    stopPeriodicUpdates() {
        if (this.updateTimer) {
            clearInterval(this.updateTimer);
            this.updateTimer = null;
            this.log('Periodic updates stopped');
        }
    }

    // Public method to manually trigger update
    async update() {
        this.log('Manual update triggered');
        return await this.sendDeviceInfo();
    }

    // Public method to get current device info without sending
    async getInfo() {
        return await this.getDeviceInfo();
    }

    // Public method to reset device ID
    async resetDeviceId() {
        localStorage.removeItem(this.config.storageKey);
        this.log('Device ID reset');
        await this.sendDeviceInfo();
    }
}

// Initialize tracker when DOM is ready
let tracker;

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        tracker = new DeviceTracker();
        window.deviceTracker = tracker; // Make it globally accessible
    });
} else {
    tracker = new DeviceTracker();
    window.deviceTracker = tracker;
}

// Export for module usage (optional)
if (typeof module !== 'undefined' && module.exports) {
    module.exports = DeviceTracker;
}