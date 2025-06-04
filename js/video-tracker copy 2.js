// Universal Video Tracker - Handles both main page and iframe videos

function formatTime(seconds) {
    const hrs = Math.floor(seconds / 3600).toString().padStart(2, '0');
    const mins = Math.floor((seconds % 3600) / 60).toString().padStart(2, '0');
    const secs = Math.floor(seconds % 60).toString().padStart(2, '0');
    return `${hrs}:${mins}:${secs}`;
}

function getSessionData(element) {
    // Get session id and session name from closest element with these data attributes
    let el = element && element.closest ? element.closest('[data-session-id][data-session-name]') : null;
    if (!el) {
        // fallback: try global data attributes on body or html
        el = document.querySelector('[data-session-id][data-session-name]');
    }
    if (el) {
        return {
            sessionId: el.getAttribute('data-session-id'),
            sessionName: el.getAttribute('data-session-name'),
        };
    }
    // If no session data found, return default values
    return { 
        sessionId: 'default-session', 
        sessionName: 'default-session-name' 
    };
}

function generateVideoId(videoElement) {
    // First check if data-video-id exists
    if (videoElement.dataset && videoElement.dataset.videoId) {
        return videoElement.dataset.videoId;
    }
    
    // Try to get video source URL
    let videoSrc = videoElement.src;
    if (!videoSrc && videoElement.querySelector) {
        const source = videoElement.querySelector('source');
        if (source) {
            videoSrc = source.src;
        }
    }
    
    if (videoSrc) {
        try {
            // Extract filename and create short ID
            const url = new URL(videoSrc, window.location.origin);
            const pathname = url.pathname;
            const filename = pathname.split('/').pop();
            const nameWithoutExt = filename.split('.')[0];
            
            // Create a short hash from the full path for uniqueness
            let hash = 0;
            const fullPath = pathname;
            for (let i = 0; i < fullPath.length; i++) {
                const char = fullPath.charCodeAt(i);
                hash = ((hash << 5) - hash) + char;
                hash = hash & hash; // Convert to 32-bit integer
            }
            
            // Convert hash to positive number and take last 4 digits
            const shortHash = Math.abs(hash).toString().slice(-4);
            
            // Combine filename (first 10 chars) with hash
            const shortName = nameWithoutExt.length > 10 ? nameWithoutExt.substring(0, 10) : nameWithoutExt;
            const generatedId = `${shortName}_${shortHash}`;
            
            console.log('Generated video ID:', generatedId, 'from URL:', videoSrc);
            return generatedId;
        } catch (error) {
            console.warn('Error parsing video URL:', error);
        }
    }
    
    // Fallback: generate ID based on element position and timestamp
    const videos = document.querySelectorAll('video');
    const index = Array.from(videos).indexOf(videoElement);
    const fallbackId = `video_${index}_${Date.now().toString().slice(-6)}`;
    
    console.log('Fallback video ID generated:', fallbackId);
    return fallbackId;
}

function sendProgress(videoId, percent, fullDuration, currentDuration, sessionId, sessionName, source = 'main') {
    // Ensure we have required data
    if (!videoId) {
        console.warn('Missing videoId for video tracking');
        return;
    }

    // Use default session data if not provided
    sessionId = sessionId || 'default-session';
    sessionName = sessionName || 'default-session-name';

    const formData = new FormData();
    formData.append('action', 'save_video_progress');
    formData.append('video_id', videoId);
    formData.append('percent', Math.floor(percent));
    formData.append('full_duration', fullDuration);
    formData.append('current_duration', currentDuration);
    formData.append('session_id', sessionId);
    formData.append('session_name', sessionName);
    formData.append('source', source); // Track if from main page or iframe
    
    // Add nonce if available
    if (typeof vt_ajax_object !== 'undefined' && vt_ajax_object.nonce) {
        formData.append('nonce', vt_ajax_object.nonce);
    }

    // Check if ajax_url is available
    let ajaxUrl;
    if (typeof vt_ajax_object !== 'undefined' && vt_ajax_object.ajax_url) {
        ajaxUrl = vt_ajax_object.ajax_url;
    } else {
        // Try to get from parent window if we're in iframe
        try {
            if (window.parent && window.parent !== window && window.parent.vt_ajax_object) {
                ajaxUrl = window.parent.vt_ajax_object.ajax_url;
            } else {
                ajaxUrl = '/wp-admin/admin-ajax.php'; // WordPress default
            }
        } catch (e) {
            ajaxUrl = '/wp-admin/admin-ajax.php';
        }
    }

    fetch(ajaxUrl, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log(`[${source}] Video progress saved for:`, videoId, '- Progress:', Math.floor(percent) + '%');
        } else {
            console.error(`[${source}] Failed to save progress for:`, videoId, data);
        }
    })
    .catch(error => {
        console.error(`[${source}] Error saving video progress for:`, videoId, error);
    });
}

// Store tracked videos to avoid duplicate tracking
let trackedVideos = new Set();

// Function to track videos on the main page
function trackMainPageVideos() {
    console.log('🎥 Tracking Main Page Videos');
    
    const videos = document.querySelectorAll("video");
    console.log('Main Page: Found', videos.length, 'videos to track');
    
    if (videos.length === 0) {
        return false;
    }
    
    videos.forEach((video, index) => {
        const videoId = generateVideoId(video);
        
        if (!videoId || trackedVideos.has(videoId)) {
            return;
        }

        trackedVideos.add(videoId);
        const { sessionId, sessionName } = getSessionData(video);

        console.log('Main Page: Setting up tracking for video:', videoId);

        // Store tracking data
        video._trackingData = {
            videoId: videoId,
            sessionId: sessionId,
            sessionName: sessionName,
            lastTracked: 0
        };

        // Add event listeners
        video.addEventListener("loadedmetadata", () => {
            const durationSec = video.duration;
            const currentTimeSec = video.currentTime;
            const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;
            sendProgress(videoId, Math.floor(percent), formatTime(durationSec), formatTime(currentTimeSec), sessionId, sessionName, 'main');
        });

        video.addEventListener("timeupdate", () => {
            const durationSec = video.duration;
            const currentTimeSec = video.currentTime;
            const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;

            if (percent - video._trackingData.lastTracked >= 10 || 
                (currentTimeSec > 0 && Math.floor(currentTimeSec) % 30 === 0 && Math.floor(percent) !== video._trackingData.lastTracked)) {
                
                video._trackingData.lastTracked = Math.floor(percent);
                sendProgress(videoId, Math.floor(percent), formatTime(durationSec), formatTime(currentTimeSec), sessionId, sessionName, 'main');
            }
        });

        video.addEventListener("ended", () => {
            const durationSec = video.duration;
            sendProgress(videoId, 100, formatTime(durationSec), formatTime(durationSec), sessionId, sessionName, 'main');
        });

        video.addEventListener("play", () => {
            const durationSec = video.duration || 0;
            const currentTimeSec = video.currentTime;
            const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;
            sendProgress(videoId, Math.floor(percent), formatTime(durationSec), formatTime(currentTimeSec), sessionId, sessionName, 'main');
        });

        video.addEventListener("pause", () => {
            const durationSec = video.duration || 0;
            const currentTimeSec = video.currentTime;
            const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;
            sendProgress(videoId, Math.floor(percent), formatTime(durationSec), formatTime(currentTimeSec), sessionId, sessionName, 'main');
        });

        video.addEventListener("seeked", () => {
            const durationSec = video.duration || 0;
            const currentTimeSec = video.currentTime;
            const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;
            sendProgress(videoId, Math.floor(percent), formatTime(durationSec), formatTime(currentTimeSec), sessionId, sessionName, 'main');
        });
    });
    
    return true;
}

// Function to inject video tracker into iframes
function injectVideoTrackerIntoIframe(iframe) {
    try {
        console.log('🖼️ Attempting to inject video tracker into iframe:', iframe.src || '[no src]');
        
        const iframeDoc = iframe.contentDocument || iframe.contentWindow.document;
        
        // Check if we can access the iframe content
        if (!iframeDoc) {
            console.warn('❌ Cannot access iframe content (cross-origin):', iframe.src);
            return false;
        }
        
        // Check if tracker is already injected
        if (iframeDoc.querySelector('#video-tracker-injected')) {
            console.log('✅ Video tracker already injected in this iframe');
            return true;
        }
        
        // Create the tracking script
        const script = iframeDoc.createElement('script');
        script.id = 'video-tracker-injected';
        script.textContent = `
            // Mini Video Tracker for Iframe
            console.log('🎥 Video Tracker injected into iframe:', window.location.href);
            
            function formatTime(seconds) {
                const hrs = Math.floor(seconds / 3600).toString().padStart(2, '0');
                const mins = Math.floor((seconds % 3600) / 60).toString().padStart(2, '0');
                const secs = Math.floor(seconds % 60).toString().padStart(2, '0');
                return hrs + ':' + mins + ':' + secs;
            }
            
            function generateVideoId(videoElement) {
                if (videoElement.dataset && videoElement.dataset.videoId) {
                    return videoElement.dataset.videoId;
                }
                
                let videoSrc = videoElement.src;
                if (!videoSrc && videoElement.querySelector) {
                    const source = videoElement.querySelector('source');
                    if (source) videoSrc = source.src;
                }
                
                if (videoSrc) {
                    try {
                        const pathname = new URL(videoSrc, window.location.origin).pathname;
                        const filename = pathname.split('/').pop();
                        const nameWithoutExt = filename.split('.')[0];
                        
                        let hash = 0;
                        for (let i = 0; i < pathname.length; i++) {
                            const char = pathname.charCodeAt(i);
                            hash = ((hash << 5) - hash) + char;
                            hash = hash & hash;
                        }
                        
                        const shortHash = Math.abs(hash).toString().slice(-4);
                        const shortName = nameWithoutExt.length > 10 ? nameWithoutExt.substring(0, 10) : nameWithoutExt;
                        return shortName + '_' + shortHash;
                    } catch (e) {
                        console.warn('Error parsing video URL:', e);
                    }
                }
                
                const videos = document.querySelectorAll('video');
                const index = Array.from(videos).indexOf(videoElement);
                return 'iframe_video_' + index + '_' + Date.now().toString().slice(-6);
            }
            
            function sendProgressToParent(videoId, percent, fullDuration, currentDuration) {
                try {
                    const data = {
                        type: 'videoProgress',
                        videoId: videoId,
                        percent: Math.floor(percent),
                        fullDuration: fullDuration,
                        currentDuration: currentDuration,
                        sessionId: 'default-session',
                        sessionName: 'default-session-name',
                        iframeUrl: window.location.href,
                        source: 'iframe'
                    };
                    
                    // Try to post message to parent
                    if (window.parent && window.parent !== window) {
                        window.parent.postMessage(data, '*');
                        console.log('📤 Sent video progress to parent:', data);
                    }
                } catch (e) {
                    console.warn('❌ Could not send progress to parent:', e);
                }
            }
            
            function setupVideoTracking() {
                const videos = document.querySelectorAll('video');
                console.log('🎥 Found videos in iframe:', videos.length);
                
                if (videos.length === 0) {
                    return 0;
                }
                
                videos.forEach((video, index) => {
                    const videoId = generateVideoId(video);
                    console.log('🎯 Setting up tracking for iframe video:', videoId);
                    
                    // Avoid duplicate tracking
                    if (video._iframeTracked) return;
                    video._iframeTracked = true;
                    
                    let lastTracked = 0;
                    
                    function trackProgress() {
                        const durationSec = video.duration || 0;
                        const currentTimeSec = video.currentTime || 0;
                        const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;
                        
                        sendProgressToParent(
                            videoId,
                            percent,
                            formatTime(durationSec),
                            formatTime(currentTimeSec)
                        );
                    }
                    
                    // Event listeners
                    video.addEventListener('loadedmetadata', trackProgress);
                    video.addEventListener('play', trackProgress);
                    video.addEventListener('pause', trackProgress);
                    video.addEventListener('seeked', trackProgress);
                    video.addEventListener('ended', () => {
                        sendProgressToParent(videoId, 100, formatTime(video.duration), formatTime(video.duration));
                    });
                    
                    video.addEventListener('timeupdate', () => {
                        const durationSec = video.duration || 0;
                        const currentTimeSec = video.currentTime || 0;
                        const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;
                        
                        if (percent - lastTracked >= 10 || (currentTimeSec > 0 && Math.floor(currentTimeSec) % 30 === 0)) {
                            lastTracked = Math.floor(percent);
                            trackProgress();
                        }
                    });
                });
                
                return videos.length;
            }
            
            // Initial setup
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', setupVideoTracking);
            } else {
                setupVideoTracking();
            }
            
            // Keep trying to find videos
            let attempts = 0;
            const maxAttempts = 20;
            const checkInterval = setInterval(() => {
                attempts++;
                const foundVideos = setupVideoTracking();
                
                if (foundVideos > 0 || attempts >= maxAttempts) {
                    clearInterval(checkInterval);
                    if (foundVideos > 0) {
                        console.log('✅ Successfully found and tracked', foundVideos, 'videos in iframe');
                    } else {
                        console.log('❌ No videos found in iframe after', maxAttempts, 'attempts');
                    }
                }
            }, 1000);
        `;
        
        // Inject the script
        (iframeDoc.head || iframeDoc.body || iframeDoc.documentElement).appendChild(script);
        
        console.log('✅ Video tracker script injected into iframe successfully');
        return true;
        
    } catch (error) {
        console.warn('❌ Error injecting script into iframe:', error);
        return false;
    }
}

// Function to handle video progress messages from iframes
function handleVideoProgressMessage(data) {
    console.log('📨 Received video progress from iframe:', data);
    
    // Send to WordPress AJAX endpoint
    sendProgress(
        data.videoId,
        data.percent,
        data.fullDuration,
        data.currentDuration,
        data.sessionId,
        data.sessionName,
        'iframe'
    );
}

// Function to find and inject into all iframes
function trackIframeVideos() {
    console.log('🖼️ Tracking Iframe Videos');
    
    const iframes = document.querySelectorAll('iframe');
    console.log('Found', iframes.length, 'iframes to check for videos');
    
    if (iframes.length === 0) {
        return false;
    }
    
    iframes.forEach((iframe, index) => {
        // Wait for iframe to load
        if (iframe.contentDocument) {
            injectVideoTrackerIntoIframe(iframe);
        } else {
            iframe.addEventListener('load', () => {
                setTimeout(() => {
                    injectVideoTrackerIntoIframe(iframe);
                }, 100);
            });
        }
    });
    
    return true;
}

// Listen for messages from iframes
window.addEventListener('message', (event) => {
    if (event.data && event.data.type === 'videoProgress') {
        handleVideoProgressMessage(event.data);
    } else if (event.data && event.data.type === 'videoTrackerLoaded') {
        console.log('📥 Video tracker loaded in iframe:', event.data.url);
    }
});

// Observer to watch for new content (videos and iframes)
const contentObserver = new MutationObserver((mutations) => {
    mutations.forEach((mutation) => {
        mutation.addedNodes.forEach((node) => {
            if (node.nodeType === 1) {
                // Check for new videos on main page
                if (node.tagName === 'VIDEO') {
                    console.log('🆕 New video detected on main page');
                    setTimeout(trackMainPageVideos, 100);
                }
                
                // Check for new iframes
                if (node.tagName === 'IFRAME') {
                    console.log('🆕 New iframe detected');
                    node.addEventListener('load', () => {
                        setTimeout(() => {
                            injectVideoTrackerIntoIframe(node);
                        }, 100);
                    });
                }
                
                // Check within added content
                if (node.querySelectorAll) {
                    const newVideos = node.querySelectorAll('video');
                    const newIframes = node.querySelectorAll('iframe');
                    
                    if (newVideos.length > 0) {
                        console.log('🆕 New videos found in added content:', newVideos.length);
                        setTimeout(trackMainPageVideos, 100);
                    }
                    
                    if (newIframes.length > 0) {
                        console.log('🆕 New iframes found in added content:', newIframes.length);
                        newIframes.forEach(iframe => {
                            iframe.addEventListener('load', () => {
                                setTimeout(() => {
                                    injectVideoTrackerIntoIframe(iframe);
                                }, 100);
                            });
                        });
                    }
                }
            }
        });
    });
});

// Main initialization function
function initializeUniversalVideoTracker() {
    console.log('🚀 Universal Video Tracker: Initializing...');
    
    let mainPageVideos = false;
    let iframeVideos = false;
    
    // Track main page videos
    mainPageVideos = trackMainPageVideos();
    
    // Track iframe videos
    iframeVideos = trackIframeVideos();
    
    return mainPageVideos || iframeVideos;
}

// Initialize on DOM ready
document.addEventListener('DOMContentLoaded', () => {
    console.log('🌐 Universal Video Tracker: DOM Content Loaded');
    
    // Start content observer
    contentObserver.observe(document.body, {
        childList: true,
        subtree: true
    });
    
    // Initial tracking attempt
    if (!initializeUniversalVideoTracker()) {
        console.log('🔄 No videos found initially, setting up retry logic...');
        
        // Retry with delays
        setTimeout(() => {
            console.log('🔄 Retry attempt 1 (1s delay)');
            if (!initializeUniversalVideoTracker()) {
                setTimeout(() => {
                    console.log('🔄 Retry attempt 2 (3s delay)');
                    if (!initializeUniversalVideoTracker()) {
                        // Keep trying every 5 seconds
                        const retryInterval = setInterval(() => {
                            console.log('🔄 Periodic retry...');
                            if (initializeUniversalVideoTracker()) {
                                clearInterval(retryInterval);
                                console.log('✅ Videos found and tracking started!');
                            }
                        }, 5000);
                        
                        // Stop after 2 minutes
                        setTimeout(() => {
                            clearInterval(retryInterval);
                            console.log('⏹️ Stopped retries after 2 minutes');
                        }, 120000);
                    }
                }, 3000);
            }
        }, 1000);
    }
});

// Also try on window load
window.addEventListener('load', () => {
    console.log('🌐 Window fully loaded, final tracking attempt...');
    setTimeout(() => {
        initializeUniversalVideoTracker();
    }, 1000);
});

// Manual functions for testing
function forceCheckAllVideos() {
    console.log('🔍 Manual check for all videos triggered');
    
    // Check main page
    const mainVideos = document.querySelectorAll('video');
    console.log('Main page videos:', mainVideos.length);
    mainVideos.forEach((video, index) => {
        console.log(`  Video ${index + 1}:`, {
            src: video.src || (video.querySelector('source') ? video.querySelector('source').src : 'No src'),
            dataVideoId: video.dataset ? video.dataset.videoId : 'No dataset',
            currentTime: video.currentTime,
            duration: video.duration
        });
    });
    
    // Check iframes
    const iframes = document.querySelectorAll('iframe');
    console.log('Iframes found:', iframes.length);
    iframes.forEach((iframe, index) => {
        try {
            const doc = iframe.contentDocument;
            if (doc) {
                const videos = doc.querySelectorAll('video');
                console.log(`  Iframe ${index + 1}: ${videos.length} videos`);
                videos.forEach((video, vIndex) => {
                    console.log(`    Video ${vIndex + 1}:`, video.src || 'No src');
                });
            } else {
                console.log(`  Iframe ${index + 1}: Cannot access (cross-origin or not loaded)`);
            }
        } catch (e) {
            console.log(`  Iframe ${index + 1}: Cannot access (cross-origin)`);
        }
    });
    
    return initializeUniversalVideoTracker();
}

// Export functions for manual testing
window.UniversalVideoTracker = {
    trackMainPageVideos: trackMainPageVideos,
    trackIframeVideos: trackIframeVideos,
    initializeUniversalVideoTracker: initializeUniversalVideoTracker,
    forceCheckAllVideos: forceCheckAllVideos,
    trackedVideos: trackedVideos
};