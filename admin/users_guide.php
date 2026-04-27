<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/database.php';

clms_require_roles(['admin', 'instructor']);

$pageTitle = 'User Guide | Criminology LMS';
$activeAdminPage = 'users_guide';

require_once __DIR__ . '/includes/layout-top.php';
?>
              <div class="d-flex flex-wrap justify-content-between align-items-center py-3 mb-3 gap-2">
                <div>
                  <h4 class="fw-bold mb-1">User Guide</h4>
                  <small class="text-muted">Simple step-by-step help for managing courses, questions, and exams.</small>
                </div>
              </div>

              <div class="row g-4">
                <div class="col-lg-8">
                  <div class="card">
                    <h5 class="card-header">Frequently Asked Questions</h5>
                    <div class="card-body">
                      <div class="accordion" id="userGuideAccordion">

                        <div class="accordion-item">
                          <h2 class="accordion-header" id="heading-add-questions">
                            <button
                              class="accordion-button"
                              type="button"
                              data-bs-toggle="collapse"
                              data-bs-target="#collapse-add-questions"
                              aria-expanded="true"
                              aria-controls="collapse-add-questions">
                              How do I add questions to a course?
                            </button>
                          </h2>
                          <div
                            id="collapse-add-questions"
                            class="accordion-collapse collapse show"
                            aria-labelledby="heading-add-questions"
                            data-bs-parent="#userGuideAccordion">
                            <div class="accordion-body">
                              <p class="mb-2">Use the Question Builder page to add questions one by one.</p>
                              <ol class="mb-0">
                                <li>Open <strong>Question Builder</strong>.</li>
                                <li>Select the course you want to edit.</li>
                                <li>Choose a question type.</li>
                                <li>Enter the question text, answers, and points.</li>
                                <li>Click <strong>Save Question</strong>.</li>
                              </ol>
                            </div>
                          </div>
                        </div>

                        <div class="accordion-item">
                          <h2 class="accordion-header" id="heading-grading-logic">
                            <button
                              class="accordion-button collapsed"
                              type="button"
                              data-bs-toggle="collapse"
                              data-bs-target="#collapse-grading-logic"
                              aria-expanded="false"
                              aria-controls="collapse-grading-logic">
                              How does exam scoring work?
                            </button>
                          </h2>
                          <div
                            id="collapse-grading-logic"
                            class="accordion-collapse collapse"
                            aria-labelledby="heading-grading-logic"
                            data-bs-parent="#userGuideAccordion">
                            <div class="accordion-body">
                              <p>
                                CLMS uses an <strong>all-or-nothing</strong> rule for auto-graded questions.
                                This means a learner gets full points only when the answer is completely correct.
                              </p>
                              <ul>
                                <li><strong>True/False and Multiple Choice:</strong> one correct answer only.</li>
                                <li><strong>Multiple Select:</strong> all correct options must be chosen, and no wrong options selected.</li>
                                <li><strong>Fill in the Blank:</strong> checks the correct answer text.</li>
                                <li><strong>Sequencing:</strong> order must match exactly.</li>
                              </ul>
                              <p>
                                After exam submission, CLMS compares the final score to the passing score of the course
                                and marks the learner as <strong>Passed</strong> or <strong>Not Passed</strong>.
                              </p>
                            </div>
                          </div>
                        </div>

                        <div class="accordion-item">
                          <h2 class="accordion-header" id="heading-edit-questions">
                            <button
                              class="accordion-button collapsed"
                              type="button"
                              data-bs-toggle="collapse"
                              data-bs-target="#collapse-edit-questions"
                              aria-expanded="false"
                              aria-controls="collapse-edit-questions">
                              How do I edit or remove a question?
                            </button>
                          </h2>
                          <div
                            id="collapse-edit-questions"
                            class="accordion-collapse collapse"
                            aria-labelledby="heading-edit-questions"
                            data-bs-parent="#userGuideAccordion">
                            <div class="accordion-body">
                              <p class="mb-2">
                                Go to <strong>Question Builder</strong>, pick the course, then use the buttons beside each question:
                              </p>
                              <ul class="mb-0">
                                <li><strong>Edit</strong> to update text, answers, or points.</li>
                                <li><strong>Delete</strong> to permanently remove a question.</li>
                              </ul>
                            </div>
                          </div>
                        </div>

                        <div class="accordion-item">
                          <h2 class="accordion-header" id="heading-passing-score">
                            <button
                              class="accordion-button collapsed"
                              type="button"
                              data-bs-toggle="collapse"
                              data-bs-target="#collapse-passing-score"
                              aria-expanded="false"
                              aria-controls="collapse-passing-score">
                              How do I set the passing score?
                            </button>
                          </h2>
                          <div
                            id="collapse-passing-score"
                            class="accordion-collapse collapse"
                            aria-labelledby="heading-passing-score"
                            data-bs-parent="#userGuideAccordion">
                            <div class="accordion-body">
                              <p>
                                Each course has its own passing score.
                                When creating a course, CLMS fills in the default value from <strong>Settings</strong>,
                                and you can change it any time in <strong>Manage Courses</strong>.
                              </p>
                            </div>
                          </div>
                        </div>

                        <div class="accordion-item">
                          <h2 class="accordion-header" id="heading-certificates">
                            <button
                              class="accordion-button collapsed"
                              type="button"
                              data-bs-toggle="collapse"
                              data-bs-target="#collapse-certificates"
                              aria-expanded="false"
                              aria-controls="collapse-certificates">
                              When does a learner receive a certificate?
                            </button>
                          </h2>
                          <div
                            id="collapse-certificates"
                            class="accordion-collapse collapse"
                            aria-labelledby="heading-certificates"
                            data-bs-parent="#userGuideAccordion">
                            <div class="accordion-body">
                              <p>
                                A certificate is generated automatically when a learner passes the course exam based on
                                that course’s passing score.
                              </p>
                            </div>
                          </div>
                        </div>

                      </div>
                    </div>
                  </div>
                </div>

                <div class="col-lg-4">
                  <div class="card">
                    <h5 class="card-header">Quick Links</h5>
                    <div class="card-body">
                      <p class="text-muted small mb-3">Use these shortcuts to manage your content faster.</p>
                      <div class="list-group list-group-flush">
                        <a
                          href="<?php echo htmlspecialchars($clmsWebBase . '/admin/add_question.php', ENT_QUOTES, 'UTF-8'); ?>"
                          class="list-group-item list-group-item-action">
                          <i class="bx bx-list-plus me-2"></i>Open Question Builder
                        </a>
                        <a
                          href="<?php echo htmlspecialchars($clmsWebBase . '/admin/courses.php', ENT_QUOTES, 'UTF-8'); ?>"
                          class="list-group-item list-group-item-action">
                          <i class="bx bx-book me-2"></i>Manage Courses
                        </a>
                        <a
                          href="<?php echo htmlspecialchars($clmsWebBase . '/admin/settings.php', ENT_QUOTES, 'UTF-8'); ?>"
                          class="list-group-item list-group-item-action">
                          <i class="bx bx-cog me-2"></i>System Settings
                        </a>
                      </div>
                    </div>
                  </div>
                </div>
              </div>

<?php
require_once __DIR__ . '/includes/layout-bottom.php';
