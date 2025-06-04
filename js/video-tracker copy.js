function formatTime(seconds) {
    const hrs = Math.floor(seconds / 3600).toString().padStart(2, '0');
    const mins = Math.floor((seconds % 3600) / 60).toString().padStart(2, '0');
    const secs = Math.floor(seconds % 60).toString().padStart(2, '0');
    return `${hrs}:${mins}:${secs}`;
}

function getSessionData(element) {
    // Get session id and session name from closest element with these data attributes
    let el = element.closest('[data-session-id][data-session-name]');
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
    return { sessionId: '', sessionName: '' };
}

function sendProgress(videoId, percent, fullDuration, currentDuration, sessionId, sessionName) {
    // Ensure we have required data
    if (!videoId || !sessionId || !sessionName) {
        console.warn('Missing required data for video tracking:', { videoId, sessionId, sessionName });
        return;
    }

    const formData = new FormData();
    formData.append('action', 'save_video_progress');
    formData.append('video_id', videoId);
    formData.append('percent', Math.floor(percent));
    formData.append('full_duration', fullDuration);
    formData.append('current_duration', currentDuration);
    formData.append('session_id', sessionId);
    formData.append('session_name', sessionName);
    formData.append('nonce', vt_ajax_object.nonce);

    fetch(vt_ajax_object.ajax_url, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            console.log('Video progress saved:', data);
        } else {
            console.error('Failed to save progress:', data);
        }
    })
    .catch(error => {
        console.error('Error saving video progress:', error);
    });
}

document.addEventListener("DOMContentLoaded", () => {
    // Self-hosted videos - Look for videos with required data attributes
    document.querySelectorAll("video[data-session-id][data-session-name]").forEach(video => {
        // Get video ID from data-video-id attribute or generate from src
        let videoId = video.dataset.videoId;
        if (!videoId) {
            // Generate ID from video source if not provided
            const src = video.src || (video.querySelector('source') ? video.querySelector('source').src : '');
            videoId = src ? src.split('/').pop().split('.')[0] : 'video_' + Date.now();
        }

        const { sessionId, sessionName } = getSessionData(video);
        let lastTracked = 0;

        // Only track if we have session data
        if (!sessionId || !sessionName) {
            console.warn('Missing session data for video tracking');
            return;
        }

        video.addEventListener("loadedmetadata", () => {
            // Send initial tracking when video metadata is loaded
            const durationSec = video.duration;
            const currentTimeSec = video.currentTime;
            const percent = (currentTimeSec / durationSec) * 100;

            sendProgress(
                videoId,
                Math.floor(percent),
                formatTime(durationSec),
                formatTime(currentTimeSec),
                sessionId,
                sessionName
            );
        });

        video.addEventListener("timeupdate", () => {
            const durationSec = video.duration;
            const currentTimeSec = video.currentTime;
            const percent = (currentTimeSec / durationSec) * 100;

            // Track every 10% progress or every 30 seconds, whichever comes first
            if (percent - lastTracked >= 10 || (currentTimeSec > 0 && Math.floor(currentTimeSec) % 30 === 0 && Math.floor(percent) !== lastTracked)) {
                lastTracked = Math.floor(percent);

                sendProgress(
                    videoId,
                    Math.floor(percent),
                    formatTime(durationSec),
                    formatTime(currentTimeSec),
                    sessionId,
                    sessionName
                );
            }
        });

        // Track when video ends
        video.addEventListener("ended", () => {
            const durationSec = video.duration;
            
            sendProgress(
                videoId,
                100, // Set to 100% when video ends
                formatTime(durationSec),
                formatTime(durationSec),
                sessionId,
                sessionName
            );
        });

        // Track when video starts playing
        video.addEventListener("play", () => {
            const durationSec = video.duration || 0;
            const currentTimeSec = video.currentTime;
            const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;

            sendProgress(
                videoId,
                Math.floor(percent),
                formatTime(durationSec),
                formatTime(currentTimeSec),
                sessionId,
                sessionName
            );
        });

        // Track when video is paused
        video.addEventListener("pause", () => {
            const durationSec = video.duration || 0;
            const currentTimeSec = video.currentTime;
            const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;

            sendProgress(
                videoId,
                Math.floor(percent),
                formatTime(durationSec),
                formatTime(currentTimeSec),
                sessionId,
                sessionName
            );
        });
    });

    // Load YouTube Iframe API if we have YouTube videos
    if (document.querySelector(".youtube-player[data-session-id][data-session-name]")) {
        // Check if YouTube API is already loaded
        if (typeof YT === 'undefined' || typeof YT.Player === 'undefined') {
            const tag = document.createElement('script');
            tag.src = "https://www.youtube.com/iframe_api";
            document.body.appendChild(tag);
        } else {
            // API already loaded, initialize players
            onYouTubeIframeAPIReady();
        }
    }
});

let ytPlayers = [];
let ytApiReady = false;

function onYouTubeIframeAPIReady() {
    ytApiReady = true;
    console.log('YouTube API Ready');
    
    document.querySelectorAll(".youtube-player[data-session-id][data-session-name]").forEach((div, index) => {
        const videoId = div.dataset.videoId;
        const { sessionId, sessionName } = getSessionData(div);

        // Only proceed if we have all required data
        if (!videoId || !sessionId || !sessionName) {
            console.warn('Missing required data for YouTube video tracking:', { videoId, sessionId, sessionName });
            return;
        }

        // Create unique ID for each player
        const playerId = 'yt-player-' + index + '-' + Date.now();
        div.id = playerId;

        const player = new YT.Player(playerId, {
            videoId: videoId,
            width: div.dataset.width || '560',
            height: div.dataset.height || '315',
            playerVars: {
                'enablejsapi': 1,
                'origin': window.location.origin
            },
            events: {
                onReady: (event) => {
                    console.log('YouTube player ready for video:', videoId);
                    
                    // Send initial tracking when player is ready
                    try {
                        const durationSec = player.getDuration();
                        const currentTimeSec = player.getCurrentTime();
                        const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;

                        sendProgress(
                            videoId,
                            Math.floor(percent),
                            formatTime(durationSec),
                            formatTime(currentTimeSec),
                            sessionId,
                            sessionName
                        );
                    } catch (error) {
                        console.warn('Error getting initial YouTube player data:', error);
                    }

                    // Set up interval to track progress
                    player.trackingInterval = setInterval(() => {
                        try {
                            if (player.getPlayerState() === YT.PlayerState.PLAYING) {
                                const durationSec = player.getDuration();
                                const currentTimeSec = player.getCurrentTime();
                                const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;

                                // Track every 10% progress or if enough time has passed
                                if (!player.lastTracked || percent - player.lastTracked >= 10) {
                                    player.lastTracked = Math.floor(percent);
                                    
                                    sendProgress(
                                        videoId,
                                        Math.floor(percent),
                                        formatTime(durationSec),
                                        formatTime(currentTimeSec),
                                        sessionId,
                                        sessionName
                                    );
                                }
                            }
                        } catch (error) {
                            console.warn('Error tracking YouTube progress:', error);
                        }
                    }, 3000); // Check every 3 seconds
                },
                onStateChange: (event) => {
                    try {
                        const durationSec = player.getDuration();
                        const currentTimeSec = player.getCurrentTime();
                        const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;

                        // Track on play, pause, and end
                        if (event.data === YT.PlayerState.PLAYING || 
                            event.data === YT.PlayerState.PAUSED || 
                            event.data === YT.PlayerState.ENDED) {
                            
                            const finalPercent = event.data === YT.PlayerState.ENDED ? 100 : Math.floor(percent);
                            const finalCurrentTime = event.data === YT.PlayerState.ENDED ? durationSec : currentTimeSec;
                            
                            sendProgress(
                                videoId,
                                finalPercent,
                                formatTime(durationSec),
                                formatTime(finalCurrentTime),
                                sessionId,
                                sessionName
                            );
                        }
                    } catch (error) {
                        console.warn('Error handling YouTube state change:', error);
                    }
                },
                onError: (event) => {
                    console.error('YouTube player error:', event.data);
                }
            }
        });

        // Store reference to player
        player.videoId = videoId;
        player.sessionId = sessionId;
        player.sessionName = sessionName;
        ytPlayers.push(player);
    });
}

// Utility function to validate time format (HH:MM:SS)
function validateTimeFormat(timeString) {
    const timeRegex = /^([0-1]?[0-9]|2[0-3]):[0-5][0-9]:[0-5][0-9]$/;
    return timeRegex.test(timeString);
}

// Handle page visibility change to save progress when user leaves
document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        console.log('Page hidden, saving video progress...');
        
        // Save current progress for all active self-hosted videos
        document.querySelectorAll('video[data-session-id][data-session-name]').forEach(video => {
            if (!video.paused && video.currentTime > 0) {
                const videoId = video.dataset.videoId || 'video_' + Date.now();
                const { sessionId, sessionName } = getSessionData(video);
                const durationSec = video.duration || 0;
                const currentTimeSec = video.currentTime;
                const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;

                if (sessionId && sessionName) {
                    sendProgress(
                        videoId,
                        Math.floor(percent),
                        formatTime(durationSec),
                        formatTime(currentTimeSec),
                        sessionId,
                        sessionName
                    );
                }
            }
        });

        // Save progress for YouTube players
        ytPlayers.forEach(player => {
            try {
                if (player.getPlayerState && player.getPlayerState() === YT.PlayerState.PLAYING) {
                    const durationSec = player.getDuration();
                    const currentTimeSec = player.getCurrentTime();
                    const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;

                    sendProgress(
                        player.videoId,
                        Math.floor(percent),
                        formatTime(durationSec),
                        formatTime(currentTimeSec),
                        player.sessionId,
                        player.sessionName
                    );
                }
            } catch (error) {
                console.warn('Error saving YouTube progress on page hide:', error);
            }
        });
    }
});

// Handle page unload to save final progress
window.addEventListener('beforeunload', () => {
    // Send beacon requests for final progress save (more reliable than fetch on unload)
    
    // Save self-hosted video progress
    document.querySelectorAll('video[data-session-id][data-session-name]').forEach(video => {
        if (video.currentTime > 0) {
            const videoId = video.dataset.videoId || 'video_' + Date.now();
            const { sessionId, sessionName } = getSessionData(video);
            const durationSec = video.duration || 0;
            const currentTimeSec = video.currentTime;
            const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;

            if (sessionId && sessionName) {
                const formData = new FormData();
                formData.append('action', 'save_video_progress');
                formData.append('video_id', videoId);
                formData.append('percent', Math.floor(percent));
                formData.append('full_duration', formatTime(durationSec));
                formData.append('current_duration', formatTime(currentTimeSec));
                formData.append('session_id', sessionId);
                formData.append('session_name', sessionName);
                formData.append('nonce', vt_ajax_object.nonce);

                // Use sendBeacon for reliable delivery
                navigator.sendBeacon(vt_ajax_object.ajax_url, formData);
            }
        }
    });

    // Save YouTube player progress
    ytPlayers.forEach(player => {
        try {
            if (player.getCurrentTime && player.getCurrentTime() > 0) {
                const durationSec = player.getDuration();
                const currentTimeSec = player.getCurrentTime();
                const percent = durationSec > 0 ? (currentTimeSec / durationSec) * 100 : 0;

                const formData = new FormData();
                formData.append('action', 'save_video_progress');
                formData.append('video_id', player.videoId);
                formData.append('percent', Math.floor(percent));
                formData.append('full_duration', formatTime(durationSec));
                formData.append('current_duration', formatTime(currentTimeSec));
                formData.append('session_id', player.sessionId);
                formData.append('session_name', player.sessionName);
                formData.append('nonce', vt_ajax_object.nonce);

                navigator.sendBeacon(vt_ajax_object.ajax_url, formData);
            }
        } catch (error) {
            console.warn('Error saving YouTube progress on unload:', error);
        }
    });
});

// Cleanup function for YouTube players
function cleanupYouTubePlayers() {
    ytPlayers.forEach(player => {
        try {
            if (player.trackingInterval) {
                clearInterval(player.trackingInterval);
            }
            if (player.destroy) {
                player.destroy();
            }
        } catch (error) {
            console.warn('Error cleaning up YouTube player:', error);
        }
    });
    ytPlayers = [];
}

// Clean up on page unload
window.addEventListener('unload', cleanupYouTubePlayers);

// Export functions for external use if needed
window.VideoTracker = {
    formatTime: formatTime,
    sendProgress: sendProgress,
    getSessionData: getSessionData,
    validateTimeFormat: validateTimeFormat,
    ytPlayers: ytPlayers
};
