// Device Tracker - Gets device serial number and sends to server
// Location: /js/deviceTracker.js

console.log('Device Tracker loaded'); // Debug

async function getDeviceInfo() {
    let deviceSerial = localStorage.getItem('device_serial');
    
    // Generate a unique device identifier if not exists
    if (!deviceSerial) {
        console.log('No device serial found, generating new one...'); // Debug
        deviceSerial = await generateDeviceFingerprint();
        localStorage.setItem('device_serial', deviceSerial);
        console.log('Generated device serial:', deviceSerial); // Debug
    } else {
        console.log('Using existing device serial:', deviceSerial); // Debug
    }
    
    return {
        serial: deviceSerial,
        userAgent: navigator.userAgent,
        platform: navigator.platform,
        language: navigator.language,
        screenResolution: `${screen.width}x${screen.height}`,
        timezone: Intl.DateTimeFormat().resolvedOptions().timeZone
    };
}

async function generateDeviceFingerprint() {
    // Create a unique fingerprint based on device characteristics
    const data = [
        navigator.userAgent,
        navigator.language,
        screen.colorDepth,
        screen.width,
        screen.height,
        new Date().getTimezoneOffset(),
        navigator.hardwareConcurrency || 'unknown',
        navigator.platform
    ].join('|');
    
    // Generate hash
    try {
        const buffer = new TextEncoder().encode(data);
        const hashBuffer = await crypto.subtle.digest('SHA-256', buffer);
        const hashArray = Array.from(new Uint8Array(hashBuffer));
        const hashHex = hashArray.map(b => b.toString(16).padStart(2, '0')).join('');
        return 'DEV-' + hashHex.substring(0, 16).toUpperCase();
    } catch (error) {
        console.error('Error generating fingerprint:', error);
        // Fallback to simpler method
        return 'DEV-' + Math.random().toString(36).substring(2, 15).toUpperCase();
    }
}

async function sendDeviceInfo() {
    try {
        const deviceInfo = await getDeviceInfo();
        console.log('Sending device info:', deviceInfo);
        
        const response = await fetch('../auth/trackDevice.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify(deviceInfo),
            credentials: 'same-origin' // Important for sessions
        });
        
        const result = await response.json();
        console.log('Server response:', result);
        
        if (!response.ok) {
            console.error('Failed to send device info:', result);
        } else {
            console.log('Device tracking successful:', result);
        }
    } catch (error) {
        console.error('Error tracking device:', error);
    }
}

// Send device info on page load
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => {
        console.log('DOM loaded, sending device info...');
        sendDeviceInfo();
    });
} else {
    console.log('DOM already loaded, sending device info...');
    sendDeviceInfo();
}

// Update activity every 2 minutes
setInterval(() => {
    console.log('Periodic update - sending device info...');
    sendDeviceInfo();
}, 2 * 60 * 1000);