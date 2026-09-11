import io

p = 'src/pages/dashboards/ReviewQueue.jsx'
s = io.open(p, encoding='utf-8').read()

# The response-file field currently offers only an upload. Add the
# repository alternative beside it: one answer or the other, never both.
old = """                              <div className="dash-field u-mt-3">
                                <label className="dash-label" htmlFor={`resp-file-${key}`}>
                                  Response file <span className="cell-muted">(optional)</span>
                                </label>"""

new = """                              <div className="dash-field u-mt-3">
                                <label className="dash-label" htmlFor={`resp-file-${key}`}>
                                  Response file <span className="cell-muted">(optional)</span>
                                </label>

                                {/* Two ways to answer, and they exclude each
                                    other — the submitter should never have to
                                    work out which of two files is the real
                                    answer. */}
                                <div className="resp-choice">
                                  <button
                                    type="button"
                                    className={`btn btn--outline btn-sm ${!showRepoPicker ? 'is-active' : ''}`}
                                    onClick={() => {
                                      setShowRepoPicker(false);
                                      setResponseDocumentId(null);
                                    }}
                                  >
                                    Upload a file
                                  </button>
                                  <button
                                    type="button"
                                    className={`btn btn--outline btn-sm ${showRepoPicker ? 'is-active' : ''}`}
                                    onClick={() => {
                                      setShowRepoPicker(true);
                                      setResponseFile(null);
                                      if (repoDocs.length === 0) loadRepoDocs(item);
                                    }}
                                  >
                                    Send an existing document
                                  </button>
                                </div>"""

assert old in s, 'response file field not matched'
s = s.replace(old, new, 1)

# Hide the file input when the repository option is chosen, and put the
# picker in its place.
old_input = """                                <input
                                  id={`resp-file-${key}`}
                                  type="file"
                                  className="dash-input"
                                  accept=".pdf,.doc,.docx"
                                  onChange={(e) => setResponseFile(e.target.files?.[0] || null)}
                                />
                                <p className="cell-muted u-mt-1">
                                  Attach a signed letter or issued document for the submitter to
                                  download.
                                </p>"""

new_input = """                                {!showRepoPicker && (
                                  <>
                                    <input
                                      id={`resp-file-${key}`}
                                      type="file"
                                      className="dash-input"
                                      accept=".pdf,.doc,.docx"
                                      onChange={(e) => setResponseFile(e.target.files?.[0] || null)}
                                    />
                                    <p className="cell-muted u-mt-1">
                                      Attach a signed letter or issued document for the submitter to
                                      download.
                                    </p>
                                  </>
                                )}

                                {showRepoPicker && (
                                  <div className="repo-picker">
                                    <input
                                      type="search"
                                      className="dash-input"
                                      placeholder="Search this office's approved documents"
                                      value={repoQuery}
                                      onChange={(e) => {
                                        setRepoQuery(e.target.value);
                                        loadRepoDocs(item, e.target.value);
                                      }}
                                    />

                                    {repoLoading && <p className="loading-text">Searching…</p>}

                                    {!repoLoading && repoDocs.length === 0 && (
                                      <p className="cell-muted u-mt-1">
                                        No approved documents from this office match. Only approved,
                                        public or internal documents can be sent.
                                      </p>
                                    )}

                                    {!repoLoading && repoDocs.length > 0 && (
                                      <ul className="repo-picker-list">
                                        {repoDocs.map((doc) => (
                                          <li key={doc.id}>
                                            <label className="repo-picker-option">
                                              <input
                                                type="radio"
                                                name={`repo-doc-${key}`}
                                                checked={responseDocumentId === doc.id}
                                                onChange={() => setResponseDocumentId(doc.id)}
                                              />
                                              <span className="repo-picker-body">
                                                <span className="repo-picker-title">{doc.title}</span>
                                                <span className="cell-muted">
                                                  {doc.ref}
                                                  {doc.category ? ` · ${doc.category}` : ''}
                                                  {doc.reporting_period ? ` · ${doc.reporting_period}` : ''}
                                                  {doc.version_number > 1 ? ` · v${doc.version_number}` : ''}
                                                </span>
                                              </span>
                                            </label>
                                          </li>
                                        ))}
                                      </ul>
                                    )}

                                    <p className="cell-muted u-mt-1">
                                      The submitter gets the current version of whichever document
                                      you pick, not a copy frozen today.
                                    </p>
                                  </div>
                                )}"""

assert old_input in s, 'file input not matched'
s = s.replace(old_input, new_input, 1)

io.open(p, 'w', encoding='utf-8').write(s)
print('patched picker markup')
