<?php

namespace NickDeKruijk\Beacon;

use RuntimeException;

/**
 * Thrown by `beacon:test`, so a test report is easy to tell apart on the dashboard. Every test
 * is the same issue there.
 */
class TestException extends RuntimeException {}
