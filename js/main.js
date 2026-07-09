/***************************** main.js *********************************
 * Classic Original UI + Pure PHP
 ***********************************************************************
 */

const ENDPOINT = 'upload.php';
let links = [];

document.addEventListener('DOMContentLoaded', () => {
    // Check Authentication Status
    fetch('auth.php?action=status')
        .then(res => res.json())
        .then(data => {
            if (data.authenticated) {
                O('authButtons').innerHTML = `
                    <p class="text-success font-weight-bold">Welcome back, ${data.user.name}</p>
                    <a href="auth.php?action=logout" class="btn btn-sm btn-outline-danger">Sign Out</a>
                `;
                
                // Check if we have active jobs to restore state
                fetch('active_jobs.php')
                    .then(res => res.json())
                    .then(jobData => {
                        if (jobData.activeJobs && jobData.activeJobs.length > 0) {
                            O('transfers').style.display = 'block';
                            uploadMonitor('stream.php');
                        } else {
                            O('uploadForm').style.display = 'block';
                        }
                    });
                
                hideIt('signupNotice');
                fetchDashboardStats();
                registerEvents();
            } else {
                O('uploadForm').style.display = 'none';
            }
        })
        .catch(err => console.error("Auth check failed", err));
});

function registerEvents() {
    hideIt('config');
    hideIt('transfers');
    hideIt('msg');
    hideIt('backBtn');
    hideIt('gdriveConfig');

    O('addBtn').addEventListener('click', addLink);
    
    O('backBtn').addEventListener('click', goBack);
    O('uploadBtn').addEventListener('click', startUpload);
    if(O('uploadBtnGdrive')) O('uploadBtnGdrive').addEventListener('click', startUpload);

    const cloudRadios = document.querySelectorAll('input[name="cloud"]');
    cloudRadios.forEach(radio => {
        radio.addEventListener('change', handleCloudSelection);
    });
}

const O = (i) => document.getElementById(i);
const hideIt = (id, hide = true) => {
    const el = O(id);
    if (!el) return;
    el.style.display = hide ? 'none' : 'block';
};

window.jobAction = function(jobId, action, message = '', filename = '') {
    if (action === 'log') {
        document.getElementById('errorLogFileName').innerText = decodeURI(filename);
        document.getElementById('errorLogMessage').innerText = message || 'Unknown error occurred.';
        new bootstrap.Modal(document.getElementById('errorLogModal')).show();
        return;
    }

    fetch('job_action.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ jobId: jobId, action: action })
    })
    .then(res => res.json())
    .then(data => {
        if (action === 'clear' && data.success) {
            const el = document.getElementById("dl" + jobId);
            if (el) el.remove();
        }
    })
    .catch(err => console.error("Job action failed", err));
};

function baseName(url) {    
    let sUrl = url.split("/");
    return sUrl[sUrl.length - 1] || 'file';
}

function addLink() {
    let urls = O("url").value.split("\n");
    let msg = "";
    
    urls.forEach(link => {
        link = link.trim();
        if (!link) return;

        if (!link.startsWith("http")) {
            msg = "Invalid protocol. URLs must start with http/https.";
        } else if (links.includes(link)) {
            msg = "Duplicate link detected.";
        } else {
            links.push(link);
            
            const li = document.createElement("li");
            li.className = "list-group-item p-2"; 
            const idx = links.length - 1;
            
            li.innerHTML = `
                <div class="input-group">
                    <input type="text" class="form-control" value="${decodeURI(baseName(link))}">
                    <button class="btn btn-outline-danger" type="button" onclick="removeLinkItem(this, '${link}')">
                        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-trash" viewBox="0 0 16 16">
                            <path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5zm3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0V6z"/>
                            <path fill-rule="evenodd" d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1-1-1V2a1 1 0 0 1 1-1H6a1 1 0 0 1 1-1h2a1 1 0 0 1 1 1h3.5a1 1 0 0 1 1 1v1zM4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4H4.118zM2.5 3V2h11v1h-11z"/>
                        </svg>
                    </button>
                </div>
            `;
            
            O("links").appendChild(li);
            if (!document.querySelector('input[name="cloud"]:checked')) {
                hideIt("cloudSelection", false);
            }
        }
    });

    if (msg) {
        O("alertMsg").innerText = msg;
        hideIt("msg", false);
        setTimeout(() => hideIt('msg'), 5000);
    }
    
    O("url").value = "";
}

window.removeLinkItem = function(btnElem, linkUrl) {
    // Find index of linkUrl
    const index = links.indexOf(linkUrl);
    if (index > -1) {
        links.splice(index, 1);
    }
    
    // Remove the li from DOM
    btnElem.closest("li").remove();
    
    if (links.length === 0) {
        hideIt("cloudSelection");
        hideIt("config");
        hideIt("gdriveConfig");
    }
};

function handleCloudSelection(e) {
    const provider = e.target.value;
    hideIt("msg");

    if (provider === "dav" || provider === "gdrive" || provider === "dropbox" || provider === "lms") {
        hideIt("cloudSelection");
        hideIt("controls", false);
        hideIt("backBtn", false);
        
        if (provider === "gdrive") {
            hideIt("config", true);
            hideIt("gdriveConfig", false);
        } else {
            hideIt("gdriveConfig", true);
            hideIt("config", false);
        }
    }
}

function goBack() {
    hideIt("config");
    hideIt("gdriveConfig");
    hideIt("controls");
    hideIt("msg");
    hideIt("cloudSelection", false);
    const radios = document.querySelectorAll('input[name="cloud"]');
    radios.forEach(r => r.checked = false);
}

function startUpload(e) {
    e.preventDefault();
    
    if (links.length === 0) {
        alert("Please add at least one link.");
        return;
    }

    const providerRadio = document.querySelector('input[name="cloud"]:checked');
    if (!providerRadio) return;
    
    const provider = providerRadio.value;
    const urlData = links.map((link, i) => ({
        fileName: encodeURI(O("links").children[i].querySelector('input[type="text"]').value),
        url: link
    }));
    
    let payload = {};

    if (provider === 'gdrive') {
        payload = {
            urls: JSON.stringify(urlData),
            provider: 'gdrive'
        };
    } else {
        payload = {
            urls: JSON.stringify(urlData),
            driveDir: document.querySelector('input[name="driveDir"]').value,
            user: document.querySelector('input[name="user"]').value,
            password: document.querySelector('input[name="password"]').value,
            uploadDir: document.querySelector('input[name="uploadDir"]').value,
            provider: provider
        };
        if (!payload.uploadDir.endsWith("/")) payload.uploadDir += "/";
    }

    hideIt("transfers", false);
    
    postToServer(payload);
}

function postToServer(payload) {
    const xhr = new XMLHttpRequest();
    xhr.open("POST", ENDPOINT, true);
    xhr.setRequestHeader("Content-type", "application/json");
    
    xhr.onreadystatechange = function() {
        if (this.readyState === 4) {
            if (this.status === 200 || this.status === 202) {
                console.log("Upload jobs submitted successfully");
                
                // Reset form to allow queueing more
                goBack();
                O("links").innerHTML = "";
                links = [];
                
                uploadMonitor('stream.php'); 
            } else if (this.status === 401) {
                window.location.reload();
            } else {
                O("response").innerHTML = `<div class="alert alert-danger">Error: ${this.statusText || this.status}</div>`;
            }
        }
    };
    
    xhr.send(JSON.stringify(payload));
}


function fetchDashboardStats() {
    fetch('dashboard_stats.php')
        .then(res => res.json())
        .then(data => {
            if (data.error) return;
            O('analyticsDashboard').style.display = 'block';
            O('metric-success-fail').innerText = data.success + ' / ' + data.fail;
            O('metric-volume').innerText = data.volume;
            O('metric-total-files').innerText = data.total_files;
        })
        .catch(err => console.error(err));
}
setInterval(fetchDashboardStats, 5000);

let pollInterval = null;
function uploadMonitor(sseEndpoint) {
    if (pollInterval) return; // already polling
    
    pollInterval = setInterval(() => {
        fetch("poll.php")
            .then(res => res.json())
            .then(events => {
                events.forEach(data => {
                    const id = String(data.id);
        
        if (!O("dl" + id)) {
            const div = document.createElement("div");
            div.id = "dl" + id;
            div.className = "upload-item mb-3 p-3 border rounded bg-white shadow-sm";
            div.innerHTML = `
                <div class="d-flex justify-content-between mb-2">
                    <span class="stats fw-bold text-truncate" style="max-width: 70%;"></span>
                    <span class="status badge text-bg-info">Pending...</span>
                </div>
                <div class="progress mb-2" style="height: 12px;">
                    <div class="progress-bar bar" role="progressbar" style="width: 0%;" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100"></div>
                </div>
                <div class="d-flex justify-content-between align-items-center text-muted small mt-2">
                    <span class="dlLink"></span>
                    <span class="actions"></span>
                </div>
            `;
            O("uploads").appendChild(div);
        }
        
        const el = O("dl" + id);
        let actionButtons = '';
        
        if (data.status === "init" || data.status === "downloading" || data.status === "uploading") {
            const pct = data.percentage || 0;
            const displayName = data.filename ? decodeURI(data.filename) : "Unknown File";
            el.querySelector(".stats").innerHTML = `${displayName}`;
            el.querySelector(".status").className = "status badge text-bg-primary";
            el.querySelector(".status").innerHTML = data.status === 'uploading' ? `Uploading... ${pct}%` : `Downloading... ${pct}%`;
            el.querySelector(".bar").style.width = `${pct}%`;
            el.querySelector(".bar").className = "progress-bar bar progress-bar-striped progress-bar-animated bg-primary";
            actionButtons = `<div class="btn-group"><button class="btn btn-sm btn-outline-danger" onclick="jobAction('${id}', 'stop')">Stop</button></div>`;
        } else if (data.status === "error" || data.status === "canceled" || (data.status && data.status >= 400)) {
            el.querySelector(".status").className = "status badge text-bg-danger";
            el.querySelector(".status").innerHTML = data.status === "canceled" ? `Canceled` : `Error`;
            el.querySelector(".bar").className = "progress-bar bar bg-danger";
            el.querySelector(".bar").style.width = "100%";
            const safeMsg = (data.message || '').replace(/'/g, "\\'").replace(/"/g, '&quot;');
            actionButtons = `
                <div class="btn-group">
                    <button class="btn btn-sm btn-outline-primary" onclick="jobAction('${id}', 'restart')">Restart</button>
                    <button class="btn btn-sm btn-outline-secondary" onclick="jobAction('${id}', 'log', '${safeMsg}', '${data.filename}')">View Log</button>
                </div>
            `;
        } else if (data.status === "complete") {
            el.querySelector(".status").className = "status badge text-bg-success";
            el.querySelector(".status").innerHTML = `Complete`;
            el.querySelector(".bar").style.width = "100%";
            el.querySelector(".bar").className = "progress-bar bar bg-success";
            actionButtons = `<div class="btn-group"><button class="btn btn-sm btn-outline-secondary" onclick="jobAction('${id}', 'clear')">Clear</button></div>`;
        }
        
        if (data.link && data.status === "complete") {
            el.querySelector(".dlLink").innerHTML = `<a href="${data.link}" target="_blank" class="text-decoration-none fw-bold text-success">View File &rarr;</a>`;
        }
        
        el.querySelector(".actions").innerHTML = actionButtons;
                });
            })
            .catch(err => console.error(err));
    }, 1500);
}
