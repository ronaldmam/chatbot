// script.js

const chatMessages = document.getElementById('chat-messages');
const userInput = document.getElementById('user-input');
const sendBtn = document.getElementById('send-btn');
const typingIndicator = document.getElementById('typing-indicator');

let messageQueue = [];
let debounceTimer = null;
const DEBOUNCE_DELAY = 10000; // 10 seconds

// Generate or retrieve Session ID
let sessionId = localStorage.getItem('chatSessionId');
if (!sessionId) {
    sessionId = 'sess_' + Math.random().toString(36).substr(2, 9) + Date.now().toString(36);
    localStorage.setItem('chatSessionId', sessionId);
}

// Load chat history when session is ready
loadHistory();
function addMessage(text, sender, isHtml = false) {
    const messageDiv = document.createElement('div');
    messageDiv.classList.add('message', sender);

    const contentDiv = document.createElement('div');
    contentDiv.classList.add('message-content');

    if (isHtml) {
        contentDiv.innerHTML = text;
    } else {
        contentDiv.textContent = text;
    }

    const timeDiv = document.createElement('div');
    timeDiv.classList.add('message-time');
    const now = new Date();
    timeDiv.textContent = now.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });

    messageDiv.appendChild(contentDiv);
    messageDiv.appendChild(timeDiv);
    chatMessages.appendChild(messageDiv);
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

function showTyping() {
    typingIndicator.style.display = 'flex';
    chatMessages.scrollTop = chatMessages.scrollHeight;
}

function hideTyping() {
    typingIndicator.style.display = 'none';
}

function processResponse(response) {
    // Check for media markers like [IMAGE: url] or [VIDEO: url]
    // Regex to find markers
    const imageRegex = /\[IMAGE:\s*(.*?)\]/g;
    const videoRegex = /\[VIDEO:\s*(.*?)\]/g;

    let formattedText = response.replace(imageRegex, '<img src="$1" alt="Product Image">');
    formattedText = formattedText.replace(videoRegex, '<video controls src="$1"></video>');

    // Convert newlines to <br>
    formattedText = formattedText.replace(/\n/g, '<br>');

    addMessage(formattedText, 'bot', true);
}

async function sendToBackend(messages) {
    showTyping();

    const combinedMessage = messages.join(" ");

    try {
        const response = await fetch('chatbot.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
            },
            body: JSON.stringify({
                message: combinedMessage,
                sessionId: sessionId
            })
        });

        if (!response.ok) {
            throw new Error('Network response was not ok');
        }

        const data = await response.json();
        hideTyping();

        if (data.reply) {
            processResponse(data.reply);
        } else if (data.error) {
            addMessage("Lo siento, hubo un error: " + data.error, 'bot');
        }

    } catch (error) {
        hideTyping();
        console.error('Error:', error);
        addMessage("Lo siento, no puedo conectar con el servidor en este momento.", 'bot');
    }
}

function handleUserMessage() {
    const text = userInput.value.trim();
    if (!text) return;

    addMessage(text, 'user');
    userInput.value = '';

    // Add to queue
    messageQueue.push(text);

    // Reset timer
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    // Set new timer
    debounceTimer = setTimeout(() => {
        const messagesToSend = [...messageQueue];
        messageQueue = []; // Clear queue
        sendToBackend(messagesToSend);
    }, DEBOUNCE_DELAY);
}

sendBtn.addEventListener('click', handleUserMessage);

userInput.addEventListener('keypress', (e) => {
    if (e.key === 'Enter') {
        handleUserMessage();
    }
});

async function loadHistory() {
    try {
        const response = await fetch(`get_history.php?sessionId=${sessionId}`);
        if (!response.ok) return;
        const data = await response.json();
        
        if (data.history && data.history.length > 0) {
            data.history.forEach(msg => {
                if (msg.role === 'bot') {
                    processResponse(msg.message);
                } else {
                    addMessage(msg.message, 'user', false);
                }
            });
        }
    } catch (error) {
        console.error('Error loading history:', error);
    }
}
