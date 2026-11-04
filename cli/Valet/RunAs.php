<?php

namespace Valet;

/**
 * Who a command runs as. CommandLine adds sudo or runuser only when the
 * current process is not already that user.
 */
enum RunAs
{
    /**
     * The current process user.
     */
    case Current;
    /**
     * root.
     */
    case Root;
    /**
     * The user Valet was installed for.
     */
    case User;
}
