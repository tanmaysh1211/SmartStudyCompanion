-- ════════════════════════════════════════════════════════════════
-- database/seed.sql  —  Smart Study Companion
-- ════════════════════════════════════════════════════════════════
-- Demo / development seed data.
-- Run AFTER schema.sql:
--   mysql -u root -p smart_study_companion < database/schema.sql
--   mysql -u root -p smart_study_companion < database/seed.sql
--
-- ⚠  DO NOT run this on a production database.
--    It inserts demo accounts with known passwords and
--    wipes existing data for the seeded tables.
--
-- Demo accounts created:
--   ┌──────────────────────────────┬──────────────────┐
--   │ Email                        │ Password         │
--   ├──────────────────────────────┼──────────────────┤
--   │ alice@studyai.com            │ Password@123     │
--   │ bob@studyai.com              │ Password@123     │
--   │ carol@studyai.com            │ Password@123     │
--   │ admin@studyai.com            │ Admin@2025       │
--   └──────────────────────────────┴──────────────────┘
--
-- Password hashes are bcrypt cost-12 hashes of the passwords
-- shown above. Generated with PHP:
--   password_hash('Password@123', PASSWORD_BCRYPT, ['cost'=>12])
-- ════════════════════════════════════════════════════════════════

SET FOREIGN_KEY_CHECKS = 0;
SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';
SET time_zone = '+00:00';

-- ════════════════════════════════════════════════════════════════
-- 0.  Clean slate  (seed tables only — preserves schema)
-- ════════════════════════════════════════════════════════════════

-- TRUNCATE TABLE token_blacklist;
-- TRUNCATE TABLE quiz_results;
-- TRUNCATE TABLE ai_summaries;
-- TRUNCATE TABLE notes;
-- TRUNCATE TABLE users;

DELETE FROM chat_history;
DELETE FROM token_blacklist;
DELETE FROM quiz_results;
DELETE FROM ai_summaries;
DELETE FROM notes;
DELETE FROM users;

SET FOREIGN_KEY_CHECKS = 1;

-- ════════════════════════════════════════════════════════════════
-- 1.  USERS
-- ════════════════════════════════════════════════════════════════
-- All demo passwords are:  Password@123
-- Admin password is:       Admin@2025
-- (bcrypt cost-12 hashes)
-- ════════════════════════════════════════════════════════════════

INSERT INTO users
    (id, name, email, password_hash, is_active, last_login, created_at)
VALUES
    -- 1. Alice — active student with full history
    (
        1,
        'Alice Johnson',
        'alice@studyai.com',
        '$2y$12$eImiTXuWVxfM37uY4JANjOe5XscKDjG7LiRqoGvRBqfn/X.Ra6rei',
        1,
        '2025-10-30 08:15:00',
        '2025-09-01 10:00:00'
    ),

    -- 2. Bob — active student, fewer notes
    (
        2,
        'Bob Martinez',
        'bob@studyai.com',
        '$2y$12$eImiTXuWVxfM37uY4JANjOe5XscKDjG7LiRqoGvRBqfn/X.Ra6rei',
        1,
        '2025-10-28 14:22:00',
        '2025-09-10 09:30:00'
    ),

    -- 3. Carol — newer student, minimal data
    (
        3,
        'Carol Lee',
        'carol@studyai.com',
        '$2y$12$eImiTXuWVxfM37uY4JANjOe5XscKDjG7LiRqoGvRBqfn/X.Ra6rei',
        1,
        '2025-10-31 11:00:00',
        '2025-10-15 16:45:00'
    ),

    -- 4. Admin — account management (is_active = 1)
    (
        4,
        'Admin User',
        'admin@studyai.com',
        '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uEutLcwW2',
        1,
        '2025-10-31 09:00:00',
        '2025-08-01 08:00:00'
    ),

    -- 5. Dave — inactive / deactivated account
    (
        5,
        'Dave Smith',
        'dave@studyai.com',
        '$2y$12$eImiTXuWVxfM37uY4JANjOe5XscKDjG7LiRqoGvRBqfn/X.Ra6rei',
        0,          -- deactivated
        NULL,
        '2025-09-05 12:00:00'
    );

-- ════════════════════════════════════════════════════════════════
-- 2.  NOTES
-- ════════════════════════════════════════════════════════════════
-- Realistic study-note content across different subjects.
-- Content is intentionally abbreviated here — the real app
-- populates this via PDF extraction / upload.
-- ════════════════════════════════════════════════════════════════

INSERT INTO notes
    (id, user_id, name, content, file_type, file_size,
     file_path, upload_date, last_accessed_at, created_at, deleted_at)
VALUES

-- ── Alice's Notes ────────────────────────────────────────────

(
    1, 1,
    'Operating System Notes — Chapter 7: Deadlocks',
    'Chapter 7: Deadlocks

A deadlock is a situation where a set of processes are blocked because each process is holding
a resource and waiting for another resource held by some other process.

NECESSARY CONDITIONS FOR DEADLOCK (Coffman Conditions)
All four must hold simultaneously for a deadlock to occur:

1. Mutual Exclusion
   Only one process at a time can use a resource. If another process requests that resource,
   the requesting process must be delayed until the resource has been released.

2. Hold and Wait
   A process must be holding at least one resource and waiting to acquire additional resources
   currently being held by other processes.

3. No Preemption
   Resources cannot be preempted; that is, a resource can be released only voluntarily by the
   process holding it, after that process has completed its task.

4. Circular Wait
   A set of waiting processes {P0, P1, ..., Pn} must exist such that P0 is waiting for a
   resource held by P1, P1 is waiting for a resource held by P2, ..., and Pn is waiting for
   a resource held by P0.

DEADLOCK PREVENTION
Deadlock prevention ensures that at least one of the four necessary conditions cannot hold.

- Eliminating Mutual Exclusion: Not always possible (e.g., printers cannot be shared).
- Eliminating Hold and Wait: Require a process to request all its resources before execution.
- Eliminating No Preemption: If a process requests a resource not available, release all
  currently held resources.
- Eliminating Circular Wait: Impose a total ordering on resource types and require processes
  to request resources in increasing order.

DEADLOCK AVOIDANCE — BANKER''S ALGORITHM
The Banker''s Algorithm is a deadlock avoidance algorithm. It is named after the banking
system where a bank never allocates available cash in such a way that it can no longer satisfy
the needs of all its customers.

Key Data Structures:
  - Available: Vector of length m (number of resource types).
  - Max:       n x m matrix — maximum demand of each process.
  - Allocation: n x m matrix — resources currently allocated.
  - Need:      n x m matrix — remaining resource need. Need[i][j] = Max[i][j] - Allocation[i][j]

Safety Algorithm:
  1. Let Work = Available and Finish[i] = false for all i.
  2. Find an index i such that Finish[i] = false and Need[i] <= Work.
  3. Work = Work + Allocation[i]; Finish[i] = true. Go to step 2.
  4. If Finish[i] = true for all i, then the system is in a safe state.

DEADLOCK DETECTION
If deadlock prevention and avoidance are not used, employ a detection algorithm and a
recovery scheme.

Single-instance resources: Use a wait-for graph. A cycle in the graph indicates a deadlock.
Multiple instances: Similar to the Safety Algorithm but checks for existing allocations.

DEADLOCK RECOVERY
1. Process Termination
   - Abort all deadlocked processes (expensive — much computation lost).
   - Abort one process at a time until the deadlock cycle is eliminated.

2. Resource Preemption
   - Selecting a victim: minimise cost.
   - Rollback: return the process to some safe state.
   - Starvation: the same process may always be selected as a victim.',
    'PDF Document',
    '1149.9 KB',
    'uploads/1/1730000001_os_chapter7_deadlocks.pdf',
    '2025-10-31',
    '2025-10-31 08:30:00',
    '2025-10-31 07:45:00',
    NULL
),

(
    2, 1,
    'Data Structures — Binary Search Trees',
    'Binary Search Trees (BST)

A Binary Search Tree is a rooted binary tree data structure with the following properties:
  - The left subtree of a node contains only nodes with keys less than the node''s key.
  - The right subtree of a node contains only nodes with keys greater than the node''s key.
  - Both the left and right subtrees must also be binary search trees.
  - There must be no duplicate nodes.

BST OPERATIONS & TIME COMPLEXITIES

Search:
  Average case: O(log n)
  Worst case:   O(n)  — when tree is completely unbalanced (skewed)

Insertion:
  Average case: O(log n)
  Worst case:   O(n)

Deletion (three cases):
  1. Node is a leaf       → simply remove it
  2. Node has one child   → replace node with its child
  3. Node has two children→ replace node with its in-order successor (or predecessor),
                            then delete the successor

In-order Traversal (Left → Root → Right):
  Visits all nodes in ascending sorted order.

Pre-order Traversal (Root → Left → Right):
  Useful for copying or serialising a tree.

Post-order Traversal (Left → Right → Root):
  Useful for deleting a tree (children before parent).

HEIGHT OF BST
  Best case (balanced):   h = O(log n)
  Worst case (skewed):    h = O(n)

SELF-BALANCING BSTs
To avoid worst-case O(n) operations, self-balancing trees are used:
  - AVL Tree:      maintains height balance (|height(left) - height(right)| ≤ 1)
  - Red-Black Tree: weaker balance guarantee but faster insertions/deletions

BST vs ARRAY vs LINKED LIST
  Feature        | BST (avg) | Sorted Array | Linked List
  Search         | O(log n)  | O(log n)     | O(n)
  Insert         | O(log n)  | O(n)         | O(1) if pos known
  Delete         | O(log n)  | O(n)         | O(1) if pos known',
    'PDF Document',
    '842.3 KB',
    'uploads/1/1730000002_ds_bst.pdf',
    '2025-10-28',
    '2025-10-30 14:10:00',
    '2025-10-28 10:30:00',
    NULL
),

(
    3, 1,
    'Computer Networks — TCP/IP Model',
    'TCP/IP MODEL

The TCP/IP model (also called the Internet model) is a concise framework for how data should
be packetised, addressed, transmitted, routed, and received on the internet. It has 4 layers:

LAYER 1 — NETWORK ACCESS (Link) LAYER
  Responsible for physical transmission of data on the network.
  Protocols: Ethernet, Wi-Fi (IEEE 802.11), ARP (Address Resolution Protocol)
  PDU: Frame
  Addresses: MAC address (48-bit hardware address)

LAYER 2 — INTERNET LAYER
  Handles logical addressing and routing of packets across networks.
  Protocols: IP (IPv4, IPv6), ICMP, IGMP
  PDU: Packet
  Addresses: IP address (32-bit IPv4 or 128-bit IPv6)

  IPv4 vs IPv6:
    IPv4: 32-bit, ~4.3 billion addresses, written as dotted decimal (192.168.1.1)
    IPv6: 128-bit, ~3.4×10^38 addresses, written as colon-separated hex

LAYER 3 — TRANSPORT LAYER
  Provides end-to-end communication, error detection, and flow control.

  TCP (Transmission Control Protocol):
    - Connection-oriented (requires handshake)
    - Reliable, ordered, error-checked delivery
    - Three-way handshake: SYN → SYN-ACK → ACK
    - Flow control (sliding window), congestion control
    - Used by: HTTP, HTTPS, FTP, SMTP

  UDP (User Datagram Protocol):
    - Connectionless — no handshake
    - Unreliable — no guarantee of delivery or order
    - Faster, lower overhead
    - Used by: DNS, DHCP, video streaming, VoIP, gaming

LAYER 4 — APPLICATION LAYER
  Provides protocols for specific data communications services.
  Protocols: HTTP/HTTPS (web), FTP (file transfer), SMTP/POP3/IMAP (email),
             DNS (name resolution), DHCP (IP assignment), SSH (secure shell)

THREE-WAY HANDSHAKE (TCP Connection Establishment):
  Client                    Server
    |──── SYN ─────────────→|
    |←── SYN-ACK ───────────|
    |──── ACK ─────────────→|
  Connection established

FOUR-WAY HANDSHAKE (TCP Connection Termination):
  Client                    Server
    |──── FIN ─────────────→|
    |←── ACK ───────────────|
    |←── FIN ───────────────|
    |──── ACK ─────────────→|',
    'Text File',
    '412.7 KB',
    'uploads/1/1730000003_networks_tcpip.txt',
    '2025-10-25',
    '2025-10-29 16:00:00',
    '2025-10-25 09:15:00',
    NULL
),

(
    4, 1,
    'Mathematics — Linear Algebra Basics',
    'LINEAR ALGEBRA — CORE CONCEPTS

VECTORS
  A vector is an ordered list of numbers. In R^n, a vector has n components.
  Column vector: v = [v1, v2, ..., vn]^T
  Operations: Addition, scalar multiplication, dot product, cross product

DOT PRODUCT
  a · b = Σ(ai * bi) = |a||b|cos(θ)
  Geometric meaning: measures how much two vectors point in the same direction.
  a · b = 0 → vectors are perpendicular (orthogonal)

MATRICES
  An m × n matrix has m rows and n columns.
  Matrix multiplication: (AB)_{ij} = Σ_k A_{ik} * B_{kj}
  Conditions: Columns of A must equal rows of B.

DETERMINANT
  det(A) = 0 → matrix is singular (non-invertible)
  det(A) ≠ 0 → matrix is invertible
  For 2×2: det([[a,b],[c,d]]) = ad - bc

INVERSE OF A MATRIX
  A * A^(-1) = I (identity matrix)
  Only square matrices with non-zero determinant are invertible.

EIGENVALUES AND EIGENVECTORS
  Av = λv  (v ≠ 0)
  λ is an eigenvalue; v is the corresponding eigenvector.
  Characteristic equation: det(A - λI) = 0

SYSTEMS OF LINEAR EQUATIONS
  Represented as Ax = b
  Solution methods:
    - Gaussian elimination (row reduction)
    - Cramer''s rule (for small systems)
    - Matrix inverse: x = A^(-1) * b (only if A is invertible)',
    'PDF Document',
    '654.2 KB',
    'uploads/1/1730000004_maths_linear_algebra.pdf',
    '2025-10-20',
    '2025-10-22 11:30:00',
    '2025-10-20 14:00:00',
    NULL
),

-- Soft-deleted note (tests that deleted notes don't appear)
(
    5, 1,
    'Old Chemistry Notes (deleted)',
    'These notes have been deleted and should not appear in the UI.',
    'Text File',
    '12.0 KB',
    NULL,
    '2025-09-15',
    NULL,
    '2025-09-15 10:00:00',
    '2025-10-01 09:00:00'   -- deleted_at is set
),

-- ── Bob's Notes ──────────────────────────────────────────────

(
    6, 2,
    'Python Programming — OOP Concepts',
    'OBJECT-ORIENTED PROGRAMMING IN PYTHON

CLASSES AND OBJECTS
  A class is a blueprint; an object is an instance of a class.

  class Dog:
      def __init__(self, name, breed):
          self.name  = name       # instance attribute
          self.breed = breed

      def bark(self):
          return f"{self.name} says: Woof!"

  my_dog = Dog("Rex", "Labrador")
  print(my_dog.bark())   # Rex says: Woof!

FOUR PILLARS OF OOP

1. Encapsulation
   Bundling data (attributes) and methods together inside a class.
   Use _ or __ prefix for private members:
     self._protected   (convention — can be accessed)
     self.__private    (name mangling — harder to access externally)

2. Inheritance
   A child class derives from a parent class and inherits its attributes and methods.
   class GuideDog(Dog):
       def guide(self):
           return f"{self.name} is guiding."

   super().__init__(name, breed)  — calls the parent class constructor

3. Polymorphism
   Different classes can define the same method name with different behaviour.
   Python uses duck typing: if it walks like a duck and quacks like a duck, it is a duck.

4. Abstraction
   Hide complex implementation; expose only what is necessary.
   Use abstract base classes (ABC module) to enforce method implementation in subclasses.

SPECIAL (DUNDER) METHODS
  __init__   : constructor
  __str__    : string representation (print)
  __repr__   : official string representation
  __len__    : len() support
  __eq__     : == operator
  __add__    : + operator (operator overloading)

CLASS vs INSTANCE vs STATIC METHODS
  Instance method: takes self — accesses instance attributes
  Class method:    @classmethod, takes cls — accesses class attributes
  Static method:   @staticmethod — no access to instance or class',
    'Text File',
    '378.5 KB',
    'uploads/2/1730000006_python_oop.txt',
    '2025-10-26',
    '2025-10-29 10:00:00',
    '2025-10-26 11:00:00',
    NULL
),

(
    7, 2,
    'Database Systems — SQL and Normalization',
    'DATABASE SYSTEMS — SQL AND NORMALIZATION

SQL BASICS

SELECT Statement:
  SELECT column1, column2 FROM table_name WHERE condition ORDER BY column ASC/DESC LIMIT n;

Aggregate Functions:
  COUNT(*), SUM(col), AVG(col), MAX(col), MIN(col)
  Used with GROUP BY and HAVING.

JOINs:
  INNER JOIN : returns rows with matching values in both tables
  LEFT JOIN  : all rows from left table + matched rows from right
  RIGHT JOIN : all rows from right table + matched rows from left
  FULL JOIN  : all rows from both tables (MySQL uses UNION for this)

Subqueries:
  SELECT name FROM students WHERE id IN (SELECT student_id FROM enrollments WHERE course_id = 5);

NORMALIZATION
Normalization eliminates redundancy and ensures data integrity.

First Normal Form (1NF):
  - Each column contains atomic (indivisible) values.
  - No repeating groups or arrays.

Second Normal Form (2NF):
  - Must be in 1NF.
  - All non-key attributes must be fully functionally dependent on the primary key.
  - No partial dependencies (no attribute depends on part of a composite key).

Third Normal Form (3NF):
  - Must be in 2NF.
  - No transitive dependencies (non-key attribute must not depend on another non-key attribute).

Boyce-Codd Normal Form (BCNF):
  - Stronger than 3NF.
  - For every functional dependency A → B, A must be a super key.

ACID PROPERTIES
  Atomicity    : all or nothing — transaction either fully completes or fully rolls back
  Consistency  : database moves from one valid state to another
  Isolation    : concurrent transactions do not interfere with each other
  Durability   : committed transactions persist even after system failures

INDEXES
  Speed up SELECT queries but slow down INSERT/UPDATE/DELETE.
  Types: B-Tree (default), Hash, Full-Text, Spatial
  CREATE INDEX idx_name ON table_name (column);',
    'PDF Document',
    '921.6 KB',
    'uploads/2/1730000007_db_sql_normalization.pdf',
    '2025-10-22',
    '2025-10-28 15:30:00',
    '2025-10-22 13:45:00',
    NULL
),

-- ── Carol's Notes ────────────────────────────────────────────

(
    8, 3,
    'Introduction to Machine Learning',
    'INTRODUCTION TO MACHINE LEARNING

WHAT IS MACHINE LEARNING?
Machine Learning (ML) is a subset of Artificial Intelligence that enables systems to
automatically learn and improve from experience without being explicitly programmed.

TYPES OF MACHINE LEARNING

1. Supervised Learning
   The model is trained on labelled data (input-output pairs).
   Goal: learn a mapping from inputs to outputs.
   Examples:
     - Classification: predicting a category (spam/not spam, cat/dog)
     - Regression:     predicting a continuous value (house price, temperature)
   Common algorithms: Linear Regression, Logistic Regression, Decision Trees,
                      Random Forest, SVM, Neural Networks

2. Unsupervised Learning
   The model learns patterns from unlabelled data.
   Goal: discover hidden structure in data.
   Examples:
     - Clustering:           K-Means, DBSCAN, Hierarchical Clustering
     - Dimensionality Reduction: PCA (Principal Component Analysis)
     - Association Rules:    Apriori Algorithm

3. Reinforcement Learning
   An agent learns by interacting with an environment and receiving rewards/penalties.
   Goal: maximise cumulative reward.
   Examples: game playing (Chess, Go), robotics, self-driving cars.

KEY CONCEPTS

Overfitting vs Underfitting:
  Overfitting:  model learns training data too well — poor generalisation to new data
  Underfitting: model is too simple — fails to capture underlying patterns
  Solution:     cross-validation, regularisation (L1/L2), more training data

Bias-Variance Trade-off:
  High bias   → underfitting (model is too simple)
  High variance → overfitting (model is too complex)
  Goal: find the sweet spot

Train / Validation / Test Split:
  Typical split: 70% train, 15% validation, 15% test
  Purpose:
    Train      → learn model parameters
    Validation → tune hyperparameters
    Test       → final evaluation (only used once)

EVALUATION METRICS
  Classification:
    Accuracy  = (TP + TN) / (TP + TN + FP + FN)
    Precision = TP / (TP + FP)
    Recall    = TP / (TP + FN)
    F1 Score  = 2 × (Precision × Recall) / (Precision + Recall)

  Regression:
    MAE  (Mean Absolute Error)
    MSE  (Mean Squared Error)
    RMSE (Root Mean Squared Error)
    R²   (Coefficient of Determination)',
    'Text File',
    '287.4 KB',
    'uploads/3/1730000008_ml_introduction.txt',
    '2025-10-31',
    '2025-10-31 10:45:00',
    '2025-10-31 10:00:00',
    NULL
);

-- ════════════════════════════════════════════════════════════════
-- 3.  QUIZ RESULTS
-- ════════════════════════════════════════════════════════════════

INSERT INTO quiz_results
    (id, user_id, note_id, score, total, percent, created_at)
VALUES

-- Alice — multiple attempts on Deadlocks (shows improvement)
(1,  1, 1,  6, 10, 60,  '2025-10-31 08:00:00'),
(2,  1, 1,  7, 10, 70,  '2025-10-31 09:15:00'),
(3,  1, 1,  9, 10, 90,  '2025-10-31 11:00:00'),

-- Alice — BST quiz attempts
(4,  1, 2,  5, 10, 50,  '2025-10-28 11:00:00'),
(5,  1, 2,  8, 10, 80,  '2025-10-29 14:30:00'),

-- Alice — Networks quiz
(6,  1, 3,  9, 10, 90,  '2025-10-26 10:00:00'),
(7,  1, 3, 10, 10, 100, '2025-10-27 09:30:00'),

-- Alice — Linear Algebra quiz (struggled)
(8,  1, 4,  3, 10, 30,  '2025-10-21 15:00:00'),
(9,  1, 4,  5, 10, 50,  '2025-10-22 10:00:00'),

-- Bob — Python OOP quiz
(10, 2, 6,  7, 10, 70,  '2025-10-27 13:00:00'),
(11, 2, 6,  9, 10, 90,  '2025-10-28 09:00:00'),

-- Bob — Database quiz
(12, 2, 7,  6, 10, 60,  '2025-10-23 14:00:00'),
(13, 2, 7,  8, 10, 80,  '2025-10-25 16:00:00'),
(14, 2, 7, 10, 10, 100, '2025-10-27 11:30:00'),

-- Carol — ML quiz (first attempt)
(15, 3, 8,  7, 10, 70,  '2025-10-31 10:30:00');

-- ════════════════════════════════════════════════════════════════
-- 4.  AI SUMMARIES  (cached Gemini-generated summaries)
-- ════════════════════════════════════════════════════════════════

INSERT INTO ai_summaries
    (id, note_id, user_id, summary, created_at)
VALUES

(
    1, 1, 1,
    '## Chapter 7: Deadlocks — Summary

**Deadlock** occurs when a set of processes are permanently blocked, each waiting for a resource held by another process in the set.

## Four Necessary Conditions (Coffman Conditions)

All four must hold simultaneously for a deadlock to occur:
- **Mutual Exclusion** — only one process can use a resource at a time
- **Hold and Wait** — a process holds at least one resource while waiting for more
- **No Preemption** — resources cannot be forcibly taken away
- **Circular Wait** — a circular chain of processes each waiting for the next

## Handling Deadlocks

**Prevention** eliminates at least one Coffman condition (e.g., require all resources to be requested upfront to break Hold and Wait).

**Avoidance** uses algorithms like the **Banker''s Algorithm** to ensure the system stays in a *safe state* by only granting resource requests that cannot lead to deadlock.

**Detection & Recovery** allows deadlocks to occur, detects them using a wait-for graph (single-instance) or safety-like algorithm (multi-instance), then recovers by terminating processes or preempting resources.

## Key Takeaways

- A deadlock requires all four Coffman conditions to hold simultaneously.
- The Banker''s Algorithm uses the concepts of *Available*, *Max*, *Allocation*, and *Need* matrices to determine safe states.
- **Safe state** = there exists a sequence of process execution that can complete without deadlock.
- Prevention is safest but most restrictive; detection/recovery allows more flexibility.
- Circular wait can be broken by imposing a total ordering on resource types.',
    '2025-10-31 08:20:00'
),

(
    2, 6, 2,
    '## Python OOP — Summary

**Object-Oriented Programming (OOP)** organises code around objects that combine data and behaviour.

## Core Concepts

**Classes and Objects** — A class is a blueprint; an object is an instance. The `__init__` method initialises instance attributes.

## The Four Pillars

- **Encapsulation** — bundles data and methods; use `_` (protected) or `__` (private) prefixes for access control
- **Inheritance** — child classes inherit from parent classes; use `super()` to call the parent constructor
- **Polymorphism** — same method name behaves differently across classes (duck typing in Python)
- **Abstraction** — hide complexity; expose only necessary interfaces via abstract base classes

## Special Methods

Dunder methods (`__str__`, `__repr__`, `__len__`, `__eq__`, `__add__`) enable operator overloading and built-in function support.

## Method Types

| Type | Decorator | First Arg | Accesses |
|---|---|---|---|
| Instance | none | `self` | instance & class |
| Class | `@classmethod` | `cls` | class only |
| Static | `@staticmethod` | none | neither |

## Key Takeaways

- Python uses duck typing — if an object has the right methods, it works regardless of its class.
- `__init__` is not the constructor (that is `__new__`) — it is the initialiser.
- Inheritance enables code reuse; always call `super().__init__()` in child classes.
- Encapsulation in Python is by convention (single underscore), not enforced (unlike Java/C++).
- OOP improves code organisation, reusability, and maintainability for large projects.',
    '2025-10-27 13:30:00'
),

(
    3, 8, 3,
    '## Introduction to Machine Learning — Summary

**Machine Learning (ML)** is an AI subfield where systems learn patterns from data rather than following explicit rules.

## Three Main Types

**Supervised Learning** trains on labelled data to predict outputs. Used for classification (discrete outputs) and regression (continuous outputs). Common algorithms include Linear Regression, Decision Trees, SVM, and Neural Networks.

**Unsupervised Learning** finds hidden patterns in unlabelled data through clustering (K-Means, DBSCAN) and dimensionality reduction (PCA).

**Reinforcement Learning** trains an agent to maximise reward through trial and error with an environment.

## Critical Concepts

**Overfitting vs Underfitting** — Overfitting means the model is too complex (memorises training data); underfitting means too simple (misses patterns). Regularisation (L1/L2) and cross-validation help find the balance.

**Train/Validation/Test Split** (typically 70/15/15) ensures unbiased evaluation. The test set should only be used once at the very end.

## Evaluation Metrics

For **classification**: Accuracy, Precision, Recall, and F1 Score.
For **regression**: MAE, MSE, RMSE, and R².

The choice of metric depends on the problem — for imbalanced datasets, F1 Score is more informative than accuracy alone.

## Key Takeaways

- ML learns from data; the quality and quantity of data is critical.
- Supervised learning is the most common type in industry applications.
- The bias-variance trade-off is central to building good models.
- Always separate training and test data to avoid data leakage.
- F1 Score balances precision and recall — important when false positives and false negatives have different costs.',
    '2025-10-31 10:20:00'
);

-- ════════════════════════════════════════════════════════════════
-- 5.  TOKEN BLACKLIST  (example revoked tokens for testing)
-- ════════════════════════════════════════════════════════════════
-- These are SHA-256 hashes of fake tokens — purely illustrative.

INSERT INTO token_blacklist
    (id, token_hash, user_id, expires_at, revoked_at)
VALUES
(
    1,
    'a3f5d8c2e1b4a7f0d3c6e9b2a5f8d1c4e7b0a3f6d9c2e5b8a1f4d7c0e3b6a9f2',
    1,
    '2025-11-01 00:00:00',
    '2025-10-30 18:00:00'
),
(
    2,
    'b4e6f9c2d5a8b1e4f7c0d3a6b9e2f5c8d1a4b7e0f3c6d9a2b5e8f1c4d7a0b3e6',
    2,
    '2025-10-29 12:00:00',
    '2025-10-28 20:15:00'
);

-- ════════════════════════════════════════════════════════════════
-- 6.  Verification queries
-- ════════════════════════════════════════════════════════════════
-- Run these manually to confirm seed data was inserted correctly:
--
-- SELECT id, name, email, is_active FROM users;
-- SELECT id, user_id, name, file_type, file_size, deleted_at FROM notes;
-- SELECT id, user_id, note_id, score, total, percent FROM quiz_results ORDER BY user_id, note_id;
-- SELECT id, note_id, user_id, LEFT(summary, 60) AS preview FROM ai_summaries;
-- SELECT id, user_id, expires_at FROM token_blacklist;
--
-- Expected counts:
--   users:          5  (4 active + 1 inactive)
--   notes:          8  (7 active + 1 soft-deleted)
--   quiz_results:  15
--   ai_summaries:   3
--   token_blacklist:2
-- ════════════════════════════════════════════════════════════════